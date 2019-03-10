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

class TaskSpoolerInstance
{

    protected $instance;
    protected $jobs;

    public function __construct() {
        $this->instance = '/tmp/' . hash('md5', microtime() . rand());
        $this->export = 'export TS_SLOTS=100; export TS_SOCKET=' . $this->instance . '; ';
        $this->jobs = array();
        echo $this->export . "\n";
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
            $tableData = array();
            $this->getJobs();
            $done = true;
            foreach ($this->jobs as $jobId => $job) {
                if ($job['exit'] === '-') {
                    $done = false;
                }
                // An error has occurred
                if ($job['exit'] !== '-' && $job['exit'] !== '0') {
                    echo "💥💥💥 ERROR RUNNING JOB " . $job['name'] . " 💥💥💥\n" . $job['output'];
                    exit(1);
                }
                if (in_array($jobId, $tabulated) === FALSE) {
                    array_push($tableData, array(
                        $job['name'],
                        $job['duration'],
                        $job['exit'],
                        $job['lastLine']
                    ));
                    array_push($tabulated, $jobId);
                    // Search for jobs that have this jobId as a dependency
                    foreach ($this->jobs as $dependantJobId => $dependantJob) {
                        if ($dependantJob['dependency'] !== NULL) {
                            if ($dependantJob['dependency'] == $jobId && in_array($dependantJobId, $tabulated) === FALSE) {
                                array_push($tableData, array(
                                    '-> ' . $dependantJob['name'],
                                    $dependantJob['duration'],
                                    $dependantJob['exit'],
                                    $dependantJob['lastLine']
                                ));
                                array_push($tabulated, $dependantJobId);
                            }
                            foreach ($this->jobs as $dependantTwoJobId => $dependantTwoJob) {
                                if ($dependantTwoJob['dependency'] !== NULL) {
                                    if ($dependantTwoJob['dependency'] == $dependantJobId && in_array($dependantTwoJobId, $tabulated) === FALSE) {
                                        array_push($tableData, array(
                                            '---> ' . $dependantTwoJob['name'],
                                            $dependantTwoJob['duration'],
                                            $dependantTwoJob['exit'],
                                            $dependantTwoJob['lastLine']
                                        ));
                                        array_push($tabulated, $dependantTwoJobId);

                                        foreach ($this->jobs as $dependantThreeJobId => $dependantThreeJob) {
                                            if ($dependantThreeJob['dependency'] !== NULL) {
                                                if ($dependantThreeJob['dependency'] == $dependantTwoJobId && in_array($dependantThreeJobId, $tabulated)     === FALSE) {
                                                    array_push($tableData, array(
                                                        '-----> ' . $dependantThreeJob['name'],
                                                        $dependantThreeJob['duration'],
                                                        $dependantThreeJob['exit'],
                                                        $dependantThreeJob['lastLine']
                                                    ));
                                                    array_push($tabulated, $dependantThreeJobId);
                                                }
                                            }  
                                        }
                                    }
                                }  
                            }
                        }    
                    }
                }
            }
            $table = new CliTable($tableData, $headers);
            echo $table->getTable();
        }
    }

}

class Create extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create 
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
    protected $description = 'Create and process a kbuild project';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $taskSpooler = new TaskSpoolerInstance();

        $stepOne = $taskSpooler->addJob('Docker', 'docker build --no-cache -t test -f k8s/docker/nginx-php .');
        $stepTwo = $taskSpooler->addJob('Docker2', 'docker build --no-cache -t test -f k8s/docker/nginx-php .', $stepOne);
        $stepThree = $taskSpooler->addJob('Docker3', 'docker build --no-cache -t test -f k8s/docker/nginx-php .', $stepTwo);
        $stepFour = $taskSpooler->addJob('Docker4', 'docker build --no-cache -t test -f k8s/docker/nginx-php .', $stepThree);
        $stepFive = $taskSpooler->addJob('Docker5', 'docker build --no-cache -t test -f k8s/docker/nginx-php .', $stepOne);

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
