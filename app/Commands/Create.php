<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Miloske85\php_cli_table\Table as CliTable;
use DateTime;
use Exception;
use Symfony\Component\Yaml\Yaml;
use App\Providers\TaskSpoolerInstance;
use App\Providers\DockerFiles;

/*
php ~/Code/kbuild/kbuild build --app=hiya --branch=master --environment=qa --build=6 --settings=/etc/parallax/settings.yaml
*/



// Putting it all together

class Create extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build 
        {--kubeconfig=~/.kube/config : The path to the kubeconfig file}
        {--app= : The name of the app}
        {--branch= : The name of the app branch}
        {--environment= : The name of the environment}
        {--build= : The number of the build}
        {--cloud-provider=aws : Either aws or gcp}
        {--no-docker-build : Skips Docker build if set}
        {--db-pause=60 : The amount of time in minutes to pause an Aurora instance after no activity}
        {--db-per-branch : Whether to use one database per branch}
        {--use-own-db-server : Whether to use a server explicitly spun up for this app}
        {--settings=/etc/parallax/settings.yaml : The settings.yaml file to use}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create and process a kbuild project';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        // Set the buildDirectory
        $buildDirectory = getcwd();
        $this->info("Building from $buildDirectory");

        // Load in kbuild yaml
        if (file_exists($buildDirectory . '/k8s/kbuild.yaml')) {
            $this->kbuild = Yaml::parseFile($buildDirectory . '/k8s/kbuild.yaml');
        }

        //dd($this->kbuild);

        // Load in settings
        $this->settings = Yaml::parseFile($this->option('settings'));

        // Check that options have been passed
        $toCheck = array('app', 'branch', 'build', 'environment');
        foreach ($toCheck as $key => $option) {
            if(null === $this->option($option)) {
                echo "You need to pass a value for --$option\n";
                exit(1);
            };
        }

        // Check platform-specific options
        switch ($this->option('cloud-provider')) {
            case 'aws':
                $toCheck = array();
                foreach ($toCheck as $key => $option) {
                    if(null === $this->option($option)) {
                        echo "You need to pass a value for --$option when using " . $this->option('cloud-provider') . " as a cloud provider\n";
                        exit(1);
                    };
                }
                break;
            
            case 'gcp':
                # code...
                break;
        }

        // Easy to understand version of --no-docker-build
        if ($this->option('no-docker-build') == TRUE) {
            $dockerBuild = 'No';
        }
        else {
            $dockerBuild = 'Yes';
        }

        // Output what we're building
        $this->table(
            // Headers
            [
                'App',
                'Branch',
                'Build',
                'Environment',
                'Build Docker Images'
            ],
            // Data
            [
                [
                    $this->option('app'),
                    $this->option('branch'),
                    $this->option('build'),
                    $this->option('environment'),
                    $dockerBuild
                ]
            ]
        );

        unset($dockerBuild);

        // Initialise the taskspooler
        $taskSpooler = new TaskSpoolerInstance();

        // Initialise the dockerFiles as building these typically takes the longest!
        $dockerFiles = new DockerFiles(
            array(
                'buildDirectory'=>  $buildDirectory,
                'app'           =>  $this->option('app'),
                'branch'        =>  $this->option('branch'),
                'build'         =>  $this->option('build'),
                'taskSpooler'   =>  $taskSpooler,
                'cloudProvider' =>  $this->option('cloud-provider'),
                'settings'      =>  $this->settings
            )
        );

        $this->info('Found ' . count($dockerFiles->asArray()) . ' Dockerfiles');
        $dockerFiles->asTable();

        // Check if we're building docker images on this run
        if ($this->option('no-docker-build') == FALSE) {
            // Oooh, we are. Shiny.
            $dockerFiles->buildAndPush();
        }

        // Configure the namespace first as subsequent steps depend on it
        $createNamespace = $taskSpooler->addJob('Create Namespace', "php ~/Code/kbuild/kbuild create:namespace --namespace='" . $this->option('app') . '-' . $this->option('environment') . "' --kubeconfig='" . $this->option('kubeconfig') . "' --settings='" . $this->option('settings') . "'");

        // Add a job to handle MySQL
        // Check if app uses own-db-server
        $additional = '';
        if ($this->option('use-own-db-server') !== FALSE) {
            $additional .= ' --use-own-db-server';
        }
        if ($this->option('db-per-branch') !== FALSE) {
            $additional .= ' --db-per-branch';
        }
        $taskSpooler->addJob('MySQL', "php ~/Code/kbuild/kbuild create:mysql --cloud-provider='" . $this->option('cloud-provider') . "' --app=" . $this->option('app') . " --branch=" . $this->option('branch') . " --environment=" . $this->option('environment') . " --settings=" . $this->option('settings') . " --kubeconfig=" . $this->option('kubeconfig') . " --db-pause=" . $this->option('db-pause') . $additional, $createNamespace);

        $taskSpooler->wait();

        print_r($taskSpooler);

    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
