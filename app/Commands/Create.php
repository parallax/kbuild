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
use App\Providers\YamlFiles;
use App\Providers\AfterDeploy;

/*
php /opt/parallax/kbuild/kbuild build --app=dashboard --branch=master --environment=prod --build=1 --kubeconfig=/Users/lawrencedudley/kubeconfig
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
        {--settings=/etc/parallax/settings.yaml : The settings.yaml file to use}
        {--ttl= : How long (in hours) to keep things created by this build alive for (useful for QA!)}';

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
            // Do a find and replace on some bits
            $kbuild = file_get_contents($buildDirectory . '/k8s/kbuild.yaml');
            $kbuild = str_replace('{{ app }}', $this->option('app'), $kbuild);
            $kbuild = str_replace('{{ environment }}', $this->option('environment'), $kbuild);
            $kbuild = str_replace('{{ namespace }}', $this->option('app') . '-' . $this->option('environment'), $kbuild);
            $kbuild = str_replace('{{ branch }}', $this->option('branch'), $kbuild);
            $kbuild = str_replace('{{ build }}', $this->option('build'), $kbuild);
            $this->kbuild = Yaml::parse($kbuild);
            unset($kbuild);
        }

        else {
            $this->kbuild = null;
        }

        // Validate Yaml Before Starting Build

        function strposa($haystack, $needles=array(), $offset=0) {
            $chr = array();
            foreach($needles as $needle) {
                    $res = strpos($haystack, $needle, $offset);
                    if ($res !== false) $chr[$needle] = $res;
            }
            if(empty($chr)) return false;
            return min($chr);
        }


        $YamlDirFiles = preg_grep('~\.yaml$~', scandir($buildDirectory . '/k8s/yaml/'));
        $yamlLintOutput = array();
        $yamlPasses = TRUE;

        $yamlLintConfig = array(
            'extends'   => 'relaxed',
            'rules'     => array(
                'new-line-at-end-of-file'   => 'disable',
                'trailing-spaces'           => 'disable',
                'comments'                  => 'disable'
            )
        );

        $yamlLintConfig = Yaml::dump($yamlLintConfig);

        //dd($yamlLintConfig);

        foreach ($YamlDirFiles as $key => $YamlDirFile) {
            exec('yamllint -d "' . $yamlLintConfig . '" ' . $buildDirectory . '/k8s/yaml/' .  $YamlDirFile, $yamlLintOutput[$key]['errors'], $yamlLintOutput[$key]['exitCode']);
            $yamlLintOutput[$key]['filename'] = $YamlDirFile;
        }

        // $output now contains an array with the yamllint output. We need to work through it and tidy it.
        foreach ($yamlLintOutput as $key => $yamlLintFile) {

            if (isset($yamlLintFile['errors'][0])) {
                unset($yamlLintOutput[$key]['errors'][0]);
            }
            foreach ($yamlLintFile['errors'] as $yamlLintOutputRowKey => $yamlLintOutputRow) {
                if ($yamlLintOutputRow === '') {
                    unset($yamlLintOutput[$key]['errors'][$yamlLintOutputRowKey]);
                }
            }
        }

        // Now extract line numbers and columns from the errors in $yamlLintOutput[x]['errors']
        foreach ($yamlLintOutput as $key => $yamlLintFile) {
            foreach ($yamlLintFile['errors'] as $errorKey => $yamlLintFileError) {
                    if (strpos($yamlLintFileError, ':')) {
                    $yamlLintOutput[$key]['parsedErrors'][$errorKey]['line'] = substr($yamlLintFileError, 0, strpos($yamlLintFileError, ':'));
                    $yamlLintOutput[$key]['parsedErrors'][$errorKey]['column'] = substr($yamlLintFileError, 0, strpos($yamlLintFileError, '    '));
                    $yamlLintOutput[$key]['parsedErrors'][$errorKey]['column'] = substr($yamlLintOutput[$key]['parsedErrors'][$errorKey]['column'], strpos($yamlLintOutput[$key]['parsedErrors'][$errorKey]['column'], ':') + 1);
                    $yamlLintOutput[$key]['parsedErrors'][$errorKey]['error'] = substr($yamlLintFileError, strposa($yamlLintFileError, array('error', 'warning')));
                }
            }
            if ($yamlLintFile['exitCode'] !== 0) {
                $yamlPasses = FALSE;
            }
        }

        $this->info('Linting YAML files in k8s/yaml');

        foreach ($yamlLintOutput as $key => $yamlLintFile) {
            if (isset($yamlLintFile['parsedErrors'])) {
                $this->info($yamlLintFile['filename']);
                $this->table(
                    // Headers
                    [
                        'Line',
                        'Column',
                        'Error'
                    ],
                    // Data
                    $yamlLintFile['parsedErrors']
                );
            }
        }

        if ($yamlPasses === FALSE) {
            echo "Please fix anything in your YAML files marked above as 'error'. Warnings are ok, errors are not.\n";
            exit(1);
        }
        else {
            $this->info('All YAML files in k8s/yaml have passed initial validation');
        }       

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

        if ($this->option('ttl') == FALSE) {
            $ttl = FALSE;
        }
        else {
            $ttl = date("U", strtotime('+' . $this->option('ttl') . ' hours'));
        }

        // Make sure no .git directories make it onto production
        $this->info("Excluding any .git folders from Docker");
        $dockerIgnore = "\n#Automatically added by kbuild\n.git\n#End automatically added by kbuild";
        file_put_contents($buildDirectory . '/.dockerignore', $dockerIgnore, FILE_APPEND);

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

        $this->info("Provisioning the following S3 buckets (after replacing placeholders):");
        $buckets = array();
        if (isset($this->kbuild['aws']['s3']) && count($this->kbuild['aws']['s3']) > 0) {
            foreach ($this->kbuild['aws']['s3'] as $key => $bucket) {
                array_push($buckets, array($bucket));
            }
        }

        $this->table(
            // Headers
            [
                'S3 Bucket',
            ],
            $buckets
        );

        unset($buckets);

        // Check for environment variables
        if (count($_ENV) == 0) {
            throw new \Exception('It looks like you have zero items in $_ENV, this suggests that your PHP is setup incorrectly. Check that your PHP ini contains variables_order=EGPCS');
        }

        $environmentVariables = array();

        // Add deploy_environment variables from bamboo_env_ keys
        foreach ($_ENV as $key => $value) {
            if (substr($key, 0, 11 ) === "bamboo_env_") {
                $environmentVariables[substr($key, 11)] = $value;
            }
        }

        if (count($environmentVariables) > 0) {

            // Cast each environment variable as a string
            foreach ($environmentVariables as $key => $value) {
                $environmentVariables[$key] = (string) $value;
            }

            // Push to an array of arrays to publish output
            $tableOutput = array();
            foreach ($environmentVariables as $key => $value) {
                $push = array($key, $value);
                array_push($tableOutput, $push);
            }
            $this->info("Environment Variables:");

            $this->table(
                array('Variable', 'Value'),
                $tableOutput
            );
        }

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
        if (count($dockerFiles->asArray()) > 0) {
            if ($this->option('no-docker-build') == FALSE) {
                // Oooh, we are. Shiny.
                $dockerFiles->buildAndPush();
            }
            elseif ($this->option('no-docker-build') !== FALSE) {
                // Just go through the motions
                $dockerFiles->buildAndPush(false);
            }
        }

        // Configure the namespace first as subsequent steps depend on it
        $createNamespace = $taskSpooler->addJob('Create Namespace', "php /opt/parallax/kbuild/kbuild create:namespace --namespace='" . $this->option('app') . '-' . $this->option('environment') . "' --kubeconfig='" . $this->option('kubeconfig') . "' --settings='" . $this->option('settings') . "'");

        // Add a job to handle MySQL if settings->mysql is set
        if (isset($this->settings['mysql'])) {

            // Check if app uses own-db-server
            $additional = '';
            if ($this->option('use-own-db-server') !== FALSE) {
                $additional .= ' --use-own-db-server';
            }
            if ($this->option('db-per-branch') !== FALSE) {
                $additional .= ' --db-per-branch';
            }
            $taskSpooler->addJob('MySQL', "php /opt/parallax/kbuild/kbuild create:mysql --cloud-provider='" . $this->option('cloud-provider') . "' --app=" . $this->option('app') . " --branch=" . $this->option('branch') . " --environment=" . $this->option('environment') . " --settings=" . $this->option('settings') . " --kubeconfig=" . $this->option('kubeconfig') . " --db-pause=" . $this->option('db-pause') . $additional, $createNamespace);

        }

        // IAM and Object Storage
        // If AWS account id is provided, sort an IAM user and buckets
        if (isset($this->settings['aws']['accountId'])) {
            $createIam = $taskSpooler->addJob('IAM User', "php /opt/parallax/kbuild/kbuild create:iam --iam-account='" . $this->option('app') . '-' . $this->option('environment') . "' --kubeconfig='" . $this->option('kubeconfig') . "' --settings='" . $this->option('settings') . "'" . " --namespace='" . $this->option('app') . '-' . $this->option('environment') . "'", $createNamespace);

            // For each S3 bucket requested in kbuild.yaml add a creation job
            if (isset($this->kbuild['aws']['s3']) && count($this->kbuild['aws']['s3']) > 0) {
                foreach ($this->kbuild['aws']['s3'] as $key => $bucket) {
                    $taskSpooler->addJob('S3 Bucket ' . $bucket, "php /opt/parallax/kbuild/kbuild create:s3bucket --iam-grantee='" . $this->option('app') . '-' . $this->option('environment') . "' --settings='" . $this->option('settings') . "'" . " --bucket-name='" . $bucket . "'", $createIam);
                }
            }
        }

        $taskSpooler->wait();

        $yamlFiles = new YamlFiles(
            array(
                'yamlDirectory' => $buildDirectory . '/k8s/yaml',
                'app'           => $this->option('app'),
                'branch'        => $this->option('branch'),
                'build'         => $this->option('build'),
                'environment'   => $this->option('environment'),
                'images'        => $dockerFiles->imageTags,
                'kbuild'        => $this->kbuild,
                'taskSpooler'   => $taskSpooler,
                'kubeconfig'    => $this->option('kubeconfig'),
                'environmentVariables'  => $environmentVariables,
                'ttl'           => $ttl
            )
        );

        $yamlFiles->queue('NetworkPolicy');
        $yamlFiles->queue('PersistentVolumeClaim');
        $yamlFiles->queue('Deployment');
        $yamlFiles->queue('StatefulSet');
        $yamlFiles->queue('ConfigMap');
        $yamlFiles->queue('ServiceAccount');
        $yamlFiles->queue('ClusterRole');
        $yamlFiles->queue('Role');
        $yamlFiles->queue('RoleBinding');
        $yamlFiles->queue('ClusterRoleBinding');
        $yamlFiles->queue('CustomResourceDefinition');
        $yamlFiles->queue('Secret');

        $taskSpooler->wait();

        $yamlFiles->queue('HorizontalPodAutoscaler');
        $yamlFiles->queue('Ingress');
        $yamlFiles->queue('IngressRoute');
        $yamlFiles->queue('Middleware');
        $yamlFiles->queue('Certificate');
        $yamlFiles->queue('Service');
        $yamlFiles->queue('PodDisruptionBudget');
        $yamlFiles->queue('CronJob');

        
        $taskSpooler->wait();

        // After Deploy
        if (isset($this->kbuild['afterDeploy'])) {
            $afterDeploy = new AfterDeploy(
                array(
                    'afterDeploy'   => $this->kbuild['afterDeploy'],
                    'kubeconfig'    => $this->option('kubeconfig'),
                    'taskSpooler'   => $taskSpooler
                )
            );
    
            $afterDeploy->delete();
            $taskSpooler->wait();
        } 
        // End After Deploy

        $taskSpooler->kill();

        // Spit out domain info
        $domains = $yamlFiles->getDomains();

        if (count($domains) > 0) {

            $this->info('Domains for access:');
    
            foreach ($domains as $key => $domain) {
                $table[$key] = array(
                    $domain
                );
            }
    
            // Output what we're building
            $this->table(
                // Headers
                [
                    'Domain',
                ],
                // Data
                $table
            );
        }

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
