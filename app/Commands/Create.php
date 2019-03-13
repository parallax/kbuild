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

/*
php ~/Code/kbuild/kbuild create --app=hiya --branch=master --environment=qa --build=6 --aws-vpc=vpc-0779d109017c7cf60 --rds-sg=sg-03b42e737045975a3 --rds-subnet-group=kops-rds
*/

class TaskSpoolerInstance
{

    protected   $instance;
    protected   $jobs;
    protected   $dependencies;
    private     $tableData;
    private     $repositoryBase;

    /* Usage:
    Initialise your taskSpooler instance
    $taskSpooler = new TaskSpoolerInstance();


    // Add jobs to your taskspool. Returns the id of the job:
    $jobId = $taskSpooler->addJob('Step One', 'docker build --no-cache -t test -f k8s/docker/nginx-php .');


    // Add a job to your taskspool that should only run when the id of another job has completed:
    $taskSpooler->addJob('Step Five', 'docker build --no-cache -t test -f k8s/docker/nginx-php .', $jobId);

    // Wait for the queue to complete:
    $taskSpooler->wait();

    // Will output pretty table as follows:
    +------------------+------------------+------------------+---------------------------------+
    | Job              | Duration         | Exit Code        | Last Output Line                |
    +------------------+------------------+------------------+---------------------------------+
    | Step One         | 0m 7s            | 0                | Successfully tagged test:latest |
    | -> Step Two      | 0m 7s            | 0                | Successfully tagged test:latest |
    | --> Step Three   | 0m 6s            | 0                | Successfully tagged test:latest |
    | ---> Step Four   | 0m 5s            | -                | ---> Running in 1453e97f6d0a    |
    | -> Step Five     | 0m 7s            | 0                | Successfully tagged test:latest |
    | Step Six         | 0m 7s            | 0                | Successfully tagged test:latest |
    +------------------+------------------+------------------+---------------------------------+

    The jobs are listed in order of dependencies with the -> bits signifying how deep down the dependency tree each function is

    */

    public function __construct() {
        $this->instance = '/tmp/' . hash('md5', microtime() . rand());
        $this->export = 'export TS_SLOTS=100; export TS_SOCKET=' . $this->instance . '; ';
        $this->jobs = array ();
        $this->dependencies = array ();
        $this->repositoryBase = '';
    }

    public function addJob($name, $command, $dependency = NULL) {

        if ($dependency !== NULL) {
            $dependencyCommand = '-D ' . $dependency . ' ';
        }

        else {
            $dependencyCommand = '';
        }
        $jobId = trim(shell_exec($this->export . 'tsp ' . "-L '$name' $dependencyCommand" .  "bash -c '$command'"));
        $info = trim(shell_exec($this->export . 'tsp -i ' . $jobId));

        $this->dependencies[$jobId]['name'] = $name;
        $this->dependencies[$jobId]['jobId'] = $jobId;

        // Add to the dependencies array that we'll use later to figure out what jobs depend on each other
        if ($dependency !== NULL) {
            // This job has a dependency. Add it to the dependencies array and figure out which level it sits at.
            // First check the job this depends on exists, error if not
            if (!isset($this->dependencies[$dependency])) {
                echo "💥💥💥 Job \"$name\" depends on JobID $dependency which doesn't exist 💥💥💥\n";
                exit(1);
            }
            // If it does in fact exist...
            // Push the dependency to the children array of the job this job depends on
            array_push($this->dependencies[$dependency]['children'], $jobId);
            // Then create a top-level item with the level set to that of the job it is dependent on +1
            $this->dependencies[$jobId]['level'] = $this->dependencies[$dependency]['level'] + 1;
            if (!isset($this->dependencies[$jobId]['children'])) {
                $this->dependencies[$jobId]['children'] = array ();
            }
        }

        else {
            $this->dependencies[$jobId]['level'] = 0;
            if (!isset($this->dependencies[$jobId]['children'])) {
                $this->dependencies[$jobId]['children'] = array ();
            }
        }

        $this->jobs[$jobId] = array(
            'id' => $jobId,
            'name' => $name,
            'status' => trim(shell_exec($this->export . 'tsp -s ' . $jobId)),
            'exit' => $this->getExitCode($info),
            'command' => $this->getCommand($info),
            'dependency' => $dependency
        );

        if ($this->jobs[$jobId]['status'] !== 'queued' && $this->jobs[$jobId]['status'] !== 'skipped') {
            $this->jobs[$jobId]['output'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId))));
            $this->jobs[$jobId]['lastLine'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId)) . '| tail -n 1'));
            $this->jobs[$jobId]['exit'] = $this->getExitCode($info);
            $this->jobs[$jobId]['duration'] = $this->getDuration($info);
        }
        else {
            $this->jobs[$jobId]['duration'] = 'Queued';
            $this->jobs[$jobId]['exit'] = '-';
            $this->jobs[$jobId]['lastLine'] = '';
            $this->jobs[$jobId]['output'] = '';
        }

        return $jobId;
    }

    public function getJobs() {
        foreach ($this->jobs as $jobId => $job) {
            $info = trim(shell_exec($this->export . 'tsp -i ' . $jobId));
            $this->jobs[$jobId]['status'] = trim(shell_exec($this->export . 'tsp -s ' . $jobId));
            $this->jobs[$jobId]['exit'] = $this->getExitCode($info);
            if ($this->jobs[$jobId]['status'] !== 'queued' && $this->jobs[$jobId]['status'] !== 'skipped') {

                $this->jobs[$jobId]['output'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId))));
                $this->jobs[$jobId]['lastLine'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId)) . '| tail -n 1'));
                $this->jobs[$jobId]['duration'] = $this->getDuration($info);
            }
            else {
                $this->jobs[$jobId]['duration'] = 'Queued';
            }
        }

        return $this->jobs;
    }

    public function getExitCode($info) {
        $lines = explode("\n", $info);
        foreach ($lines as $key => $line) {
            if(strpos($line, 'Exit status: died with exit code ') === 0) {
                $exit = str_replace('Exit status: died with exit code ', '', $line);
            }
            if(strpos($line, 'Exit status: killed by signal ') === 0) {
                $exit = str_replace('Exit status: killed by signal ', '', $line);
            }
        }
        if (!isset($exit)) {
            $exit = '-';
        }
        return $exit;
    }

    public function getCommand($info) {
        $lines = explode("\n", $info);
        foreach ($lines as $key => $line) {
            if(strpos($line, 'Command: ') === 0) {
                $command = str_replace('Command: ', '', $line);
            }
        }

        return $command;
    }

    public function getDuration($info) {
        $lines = explode("\n", $info);
        foreach ($lines as $key => $line) {
            if(strpos($line, 'Start time: ') === 0) {
                $startTime = new DateTime(str_replace('Start time: ', '', $line));
            }
            if(strpos($line, 'End time: ') === 0) {
                $endTime = new DateTime(str_replace('End time: ', '', $line));
            }
        }

        if(!isset($endTime)) {
            $endTime = new DateTime();
        }

        $interval = date_diff($startTime, $endTime);

        return $interval->format("%im %ss");
    }

    private function dependencyIterator($job) {


        // Set the name up with -- and > if it's a child
        if ($job['level'] === 0) {
            $name = $job['name'];
        }
        else {
            $name = str_repeat('-', $job['level']) . '> ' . $job['name'];
        }
        $jobId = $job['jobId'];

        array_push($this->tableData, array(
            'name' => $name,
            'duration' => $this->jobs[$jobId]['duration'],
            'exit' => $this->jobs[$jobId]['exit'],
            'lastLine' => $this->jobs[$jobId]['lastLine'],
        ));

        if (count($job['children']) > 0) {
            foreach ($job['children'] as $key => $child) {
                $this->dependencyIterator($this->dependencies[$child]);
            }
        }
    }

    public function wait() {
        $headers = array(
            'Job',
            'Duration',
            'Exit Code',
            'Last Output Line',
        );
        $done = false;

        while ($done !== true) {
            sleep(1);
            $tabulated = array();
            $this->tableData = array();
            $this->getJobs();
            $done = true;

            foreach ($this->dependencies as $jobId => $job) {
                // Filter for ones that are level 0:
                if ($job['level'] === 0) {
                    $this->dependencyIterator($job);
                }

                if ($this->jobs[$jobId]['exit'] === '-') {
                    $done = false;
                }
                // An error has occurred
                if ($this->jobs[$jobId]['exit'] !== '-' && $this->jobs[$jobId]['exit'] !== '0') {
                    echo "💥💥💥 Error running job \"" . $this->jobs[$jobId]['name'] . "\". Exit Code: " . $this->jobs[$jobId]['exit'] . " 💥💥💥\nLog:\n" . $this->jobs[$jobId]['output'];
                    exit(1);
                }
            }

            $table = new CliTable($this->tableData, $headers);
            echo $table->getTable();
        }
    }

}

class DockerFiles {

    protected $directory;
    protected $buildDirectory;
    protected $asArray;
    protected $app;
    protected $branch;
    protected $build;
    protected $taskSpooler;
    protected $cloudProvider;

    public function __construct($args) {
        $this->buildDirectory = $args['buildDirectory'];
        if (count($this->asArray()) === 0) {
            $this->noFiles();
        }
        $this->app = $args['app'];
        $this->branch = $args['branch'];
        $this->build = $args['build'];
        $this->taskSpooler = $args['taskSpooler'];
        $this->cloudProvider = $args['cloudProvider'];
    }

    public function noFiles() {
        echo "No Dockerfiles have been found - this might be an error or in some edge cases it might be fine. Beware!\n";
    }

    public function build($dockerFile) {
        exec('docker build --no-cache -t test -f ' . $this->buildDirectory . '/k8s/docker/' . $dockerFile . ' ' . $this->buildDirectory);
    }

    public function asArray() {
        // Find all docker files in k8s/docker that we need to build
        $dockerFiles = scandir($this->buildDirectory . '/k8s/docker/');

        // Filter for anything beginning with a .
        foreach ($dockerFiles as $key => $dockerFile) {
            if(strpos($dockerFile, '.') === 0) {
                unset($dockerFiles[$key]);
            }
        }

        return $dockerFiles;
    }

    public function asTableArray() {

        $response = array();

        if (count($this->asArray()) !== 0) {
            foreach ($this->asArray() as $key => $value) {
                array_push($response, array($value));
            }
            return $response;
        }
        
        else {
            return array();
        }
        

    }

    public function repositoryBase() {
        switch ($this->cloudProvider) {
            case 'aws':

                $dockerLogin = `aws ecr get-login --no-include-email`;
                preg_match('/(https:\/\/.*)/', $dockerLogin, $repositoryBase);
                $repositoryBase = str_replace('https://', '', $repositoryBase[1]);

                break;
            
            case 'gcp':
                # code...
                break;
        }

        return $repositoryBase;
    }

    public function asTable() {
        
        if (count($this->asArray()) !== 0) {
            $headers = array(
                'Dockerfile',
            );
            $table = new CliTable($this->asTableArray(), $headers);
            echo $table->getTable();
            echo "\n";
        }
    }

    public function buildAndPush() {

        $files = $this->asArray();
        $buildDirectory = $this->buildDirectory;
        $app = $this->app;
        $branch = $this->branch;
        $build = $this->build;
        $taskSpooler = $this->taskSpooler;
        $provider = $this->cloudProvider;

        // For each docker file, get it queued up as a job
        foreach ($files as $key => $dockerFile) {
    
            switch ($provider) {
                case 'aws':
    
                    $dockerLogin = `aws ecr get-login --no-include-email`;
                    preg_match('/-p (.*=)/', $dockerLogin, $dockerPassword);
                    $dockerPassword = $dockerPassword[1];
                    preg_match('/(https:\/\/.*)/', $dockerLogin, $repositoryBase);
                    $repositoryBase = $repositoryBase[1];
    
                    // Run ECR login
                    $ecrLogin = `echo $dockerPassword | docker login -u AWS --password-stdin $repositoryBase`;
    
                    // Ensure that the ECR repository exists for this app
                    // Describe the repositories on the account
                    $repositories = json_decode(`aws ecr describe-repositories`);
                    if ($repositories === NULL) {
                        echo "💥💥💥 Error getting ECR repositories. This could be an AWS or an IAM issue. 💥💥💥\n";
                        exit(1);
                    }
                    
                    // See if any of the names match
                    $repositoryExists = FALSE;
                    foreach ($repositories->repositories as $key => $repository) {
                        if ($repository->repositoryName === $app) {
                            $repositoryExists = TRUE;
                        }
                    }
    
                    // Doesn't exist, create it
                    if ($repositoryExists === FALSE) {
                        $createRepository = `aws ecr create-repository --repository-name $app`;
                    }
    
                    $repositoryBase = str_replace('https://', '', $repositoryBase);
    
                    break;
                
                case 'gcp':
                    # code...
                    break;
            }
    
            $tag = $repositoryBase . '/' . $app . ':' . $dockerFile . '-' . $branch . '-' . $build;
            $taskSpooler->addJob('Dockerfile ' . $dockerFile, 'docker build --no-cache -t ' . $tag . ' -f ' . $buildDirectory . '/k8s/docker/' . $dockerFile . ' . && docker push ' . $tag);
    
            return $repositoryBase;
        }
    }

}

// Putting it all together

class Create extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create 
        {--kubeconfig=~/.kube/config : The path to the kubeconfig file}
        {--app= : The name of the app}
        {--branch= : The name of the app branch}
        {--environment= : The name of the environment}
        {--build= : The number of the build}
        {--cloud-provider=aws : Either aws or gcp}
        {--no-docker-build : Skips Docker build if set}
        {--aws-vpc= : The VPC to use. Only used when cloud-provider is set to aws}
        {--rds-sg= : The security group to use for RDS instances}
        {--rds-subnet-group= : The RDS subnet group to use for RDS instances}
        {--db-pause= : The amount of time in minutes to pause an Aurora instance after no activity}
        {--db-per-branch : Whether to use one database per branch}';

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
                $toCheck = array('aws-vpc', 'rds-sg', 'db-pause', 'rds-subnet-group');
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

        // Set the buildDirectory
        $buildDirectory = getcwd();
        $this->info("Building from $buildDirectory");

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
                'cloudProvider' =>  $this->option('cloud-provider')
            )
        );

        $this->info('Found ' . count($dockerFiles->asArray()) . ' Dockerfiles');
        $dockerFiles->asTable();

        // Check if we're building docker images on this run
        if ($this->option('no-docker-build') == FALSE) {
            // Oooh, we are. Shiny.
            $dockerFiles->buildAndPush();
        }

        // MySQL

        $mysql = new MySQLProvisioner(
            array(
                'app'           =>  $this->option('app'),
                'branch'        =>  $this->option('branch'),
                'taskSpooler'   =>  $taskSpooler,
                'cloudProvider' =>  $this->option('cloud-provider'),
                'dbPerBranch'   =>  $this->option('db-per-branch'),
                'pause'         =>  $this->option('db-pause'),
                'environment'   =>  $this->option('environment'),
                'rds-sg'        =>  $this->option('rds-sg'),
                'aws-vpc'       =>  $this->option('aws-vpc'),
                'rds-subnet-group'  => $this->option('rds-subnet-group')
            )
        );

        $mysql->declare();

        $taskSpooler->wait();

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
