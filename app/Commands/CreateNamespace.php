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

class CreateNamespace extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create:namespace 
        {--namespace= : The namespace to create/ensure exists}
        {--settings= : The settings.yaml file to use}
        {--kubeconfig= : The path to the kubeconfig file}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Declare a Kubernetes Namespace';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        // Our function
        // Load in settings
        $this->settings = Yaml::parseFile($this->option('settings'));

        $yamlArray = array(
            'apiVersion'    => 'v1',
            'kind'          => 'Namespace',
            'metadata'      => array(
                'name'          => $this->option('namespace'),
                'labels'        => array(
                    'name'          => $this->option('namespace')
                )
            )
        );

        $command = "cat <<EOF | kubectl --kubeconfig=" . $this->option('kubeconfig') . " apply -f -\n" . Yaml::dump($yamlArray) . "\nEOF";

        system($command, $exit);
        if ($exit !== 0) {
            echo "Error creating namespace\n";
            exit(1);
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


?>