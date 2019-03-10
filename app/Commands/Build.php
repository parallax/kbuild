<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Spatie\Async\Pool as Pool;
use Spatie\Async\PoolStatus as PoolStatus;
use Spatie\Async\Task as Task;
use Spatie\Async\Process as Process;
use KubernetesRuntime\Client as KubernetesClient;
use Kubernetes\API\ConfigMap as ConfigMapAPI;
use Kubernetes\API\KubernetesNamespace as NamespaceAPI;
use Kubernetes\Model\Io\K8s\Api\Core\V1\ConfigMap;
use Kubernetes\Model\Io\K8s\Api\Core\V1\KubernetesNamespace;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Miloske85\php_cli_table\Table as CliTable;

/*
php kbuild build \
'https://api.prlx.kontainer.cloud' \
'/Users/lawrencedudley/Code/kbuild/certs/client-certificate-data' \
'/Users/lawrencedudley/Code/kbuild/certs/client-key-data' \
'/Users/lawrencedudley/Code/kbuild/certs/certificate-authority-data' \
'hiya' \
'master' \
'qa' \
'1'
*/

class CustomPool extends Pool
{
    public function waitWithStatus($poolStatus, ?callable $intermediateCallback = null): array
    {
        while ($this->inProgress) {
            foreach ($this->inProgress as $process) {
                if ($process->getCurrentExecutionTime() > $this->timeout) {
                    $this->markAsTimedOut($process);
                }
                if ($process instanceof SynchronousProcess) {
                    $this->markAsFinished($process);
                }
            }
            if (! $this->inProgress) {
                break;
            }
            if ($intermediateCallback) {
                call_user_func_array($intermediateCallback, [$this]);
            }
            usleep($this->sleepTime);

            $table = new CliTable($poolStatus->table(), $poolStatus->headers());
            echo $table->getTable();
            echo "\n";
        }

        return $this->results;
    }
}

class DeclareNamespace extends Task
{

    protected $args;
    protected $master;
    protected $authentication;
    protected $certificateAuthorityData;

    public function __construct($args) {
        $this->namespace = $args['namespace'];
        $this->master = $args['master'];
        $this->authentication = $args['authentication'];
        $this->certificateAuthorityData = $args['certificateAuthorityData'];
    }

    public function configure()
    {
        // Initialise the Kubernetes Client
        KubernetesClient::configure($this->master, $this->authentication, [
            'verify' => $this->certificateAuthorityData
        ]);
    }

    public function run()
    {
        // Do the real work here.
        // Initialise the API
        $namespaceAPI = new NamespaceAPI();

        if ($namespaceAPI->read($this->namespace)->status === 'Failure') {
            $kubernetesNamespace = new KubernetesNamespace([
                'metadata' => [
                    'name' => $this->namespace
                ],
            ]);
            $namespaceAPI->create($kubernetesNamespace);
        }

        // Check if the namespace exists
        $response = $namespaceAPI->read($this->namespace);

        return $response;
    }
}

class BuildDockerFile extends Task
{

    protected $args;
    protected $dockerFiles;
    protected $buildFile;

    public function __construct($args) {
        $this->dockerFiles = $args['dockerFiles'];
        $this->buildFile = $args['buildFile'];
    }

    public function configure()
    {
    }

    public function run()
    {
        // Do the real work here.
        // Initialise the API

        $return = $this->dockerFiles->build($this->buildFile);

        return $return;
    }
}

class PoolStatusTable
{

    protected $pool;
    protected $jobs;

    public function __construct($args) {
        $this->pool = $args['pool'];
        $this->jobs = array();
    }

    public function headers() {
        $headers = array(
            'Job',
            'Time',
            'Status'
        );
        return $headers;
    }

    public function table() {
        // Pool status
        $statusRows = array();
        foreach ($this->jobs as $process => $status) {
            $jobName = $this->jobs[$process]['jobName'];
            $process = $this->jobs[$process]['process'];
            if ($process->isRunning() === true) {
                $textStatus = 'Running';
            } elseif ($process->isSuccessful() === true) {
                $textStatus = 'Success';
            } elseif ($process->isTerminated() === true) {
                $textStatus = 'Finished';
            }
            array_push($statusRows, array(
                $jobName,
                round($process->getCurrentExecutionTime(), 2),
                $textStatus
            ));
        }

        return $statusRows;
    }

    public function addJob($job, $process) {
        $this->jobs[$process->getPid()] = array(
            'jobName' => $job,
            'process' => $process
        );
    }

    public function jobs() {
        return $this->jobs;
    }

        
}

class DockerFiles {

    protected $directory;
    protected $asArray;

    public function __construct($args) {
        $this->directory = $args['directory'];
        if (count($this->asArray()) === 0) {
            $this->noFiles();
        }
    }

    public function noFiles() {
        echo "No Dockerfiles have been found - this might be an error or in some edge cases it might be fine. Beware!\n";
    }

    public function build($dockerFile) {
        exec('docker build --no-cache -t test -f ' . $this->directory . '/k8s/docker/' . $dockerFile . ' ' . $this->directory);
    }

    public function asArray() {
        // Find all docker files in k8s/docker that we need to build
        $dockerFiles = scandir($this->directory . '/k8s/docker/');

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

}


class Build extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build 
        {master : The url for the kubernetes api}
        {client-certificate-data : Client certificate}
        {client-key-data : Client key}
        {certificate-authority-data : Certificate authority data}
        {app : The name of the app}
        {branch : The name of the app branch}
        {environment : The name of the environment}
        {build : The number of the build}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Build a kbuild project';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        // Initialise variables to pass to Kubernetes client - https://github.com/allansun/kubernetes-php-client
        $master = $this->argument('master');
        $authentication = [
            'clientCert' => $this->argument('client-certificate-data'),
            'clientKey' => $this->argument('client-key-data')
        ];

        // Create the worker pool and specify a status refresh rate for the wait function
        $pool = CustomPool::create()
            ->autoload(__DIR__ . '/../../vendor/autoload.php')
            ->sleepTime(500000);

        // Check for async support
        if ($pool->isSupported() === TRUE) {
            $this->info('Asynchronous execution is supported on this platform');
        }
        else {
            $this->error('Asynchronous execution is not supported on this platform. Builds will run more slowly.');
        }

        // Set the buildDirectory
        $buildDirectory = getcwd();
        $this->info("Building from $buildDirectory");

        // Output what we're building
        $this->table(
            // Headers
            [
                'App',
                'Branch',
                'Build',
                'Environment',
            ],
            // Data
            [
                [
                    $this->argument('app'),
                    $this->argument('branch'),
                    $this->argument('build'),
                    $this->argument('environment'),  
                ]
            ]
        );

        // Initialise our custom pool status table outputter
        $poolStatus = new PoolStatusTable(
            array(
                'pool' => $pool,
            )
        );

        // Get Dockerfiles
        $dockerFiles = new DockerFiles(
            array(
                'directory' => getcwd(),
                'pool' => $pool
            )
        );

        $this->info('Found ' . count($dockerFiles->asArray()) . ' Dockerfiles');
        $dockerFiles->asTable();

        // Job handling

        // Ensure namespace exists
        $jobName = 'Declare Namespace';
        /*$process = ($pool->add(new DeclareNamespace(
            array(
                'namespace' => $this->argument('app') . '-' . $this->argument('environment'),
                'master' => $master,
                'authentication' => $authentication,
                'certificateAuthorityData' => $this->argument('certificate-authority-data'),
            )
        )));
        // Add the process to the array so we can use pretty names
        $poolStatus->addJob($jobName, $process);*/

        // Build Dockerfile
        $jobName = 'Build nginx-php';
        $process = ($pool->add(new BuildDockerFile(
            array(
                'dockerFiles' => $dockerFiles,
                'buildFile' => 'nginx-php',
            )
        ))->then(function ($output) {
            //var_dump($output);
        })->catch(function ($exception, $process) {
            $this->error(trim('Error building Dockerfile: ' . $exception->getMessage()));
            dd($process);
            throw new Exception("Error", 1);
        }));

        // Add the process to the array so we can use pretty names
        $poolStatus->addJob($jobName, $process);

        // Run current queue and output status table
        $pool->waitWithStatus($poolStatus);


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
