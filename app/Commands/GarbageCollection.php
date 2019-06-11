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

class GarbageCollection extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'garbage 
        {--kubeconfig= : The path to the kubeconfig file}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Collect and delete kubernetes resources that are past their ttl';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get deployments --all-namespaces -o json";

     //   $ttlmarker = "[ttl]";

        // Deployments

        $deployments = shell_exec($command);

        $deployments = json_decode($deployments, TRUE);

        $resources = array();

        foreach ($deployments['items'] as $key => $deployment) {

            if (isset($deployment['metadata']['annotations']['ttl']) && $deployment['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $deployment['metadata']['name'],
                    'namespace' => $deployment['metadata']['namespace'],
                    'kind' => $deployment['kind'],
                    'ttl' => $deployment['metadata']['annotations']['ttl']
                ));
            }

        }

        // Statefulsets

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get statefulsets --all-namespaces -o json";

        $statefulsets = shell_exec($command);

        $statefulsets = json_decode($statefulsets, TRUE);

        foreach ($statefulsets['items'] as $key => $statefulset) {

            if (isset($statefulset['metadata']['annotations']['ttl']) && $statefulset['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $statefulset['metadata']['name'],
                    'namespace' => $statefulset['metadata']['namespace'],
                    'kind' => $statefulset['kind'],
                    'ttl' => $statefulset['metadata']['annotations']['ttl']
                ));
            }

        }

        $this->table(
            // Headers
            [
                'Name',
                'Namespace',
                'Kind',
                'TTL'
            ],
            // Data
            $resources
        );


        // Deletions
        foreach ($resources as $key => $resource) {
            if (isset($resource['name']) && isset($resource['namespace']) && isset($resource['kind']) && isset($resource['ttl'])) {


                $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " delete " . $resource['kind'] . " -n " . $resource['namespace'] . " " . $resource['name'];

                system($command, $exit);
                if ($exit !== 0) {
                    echo "Error deleting " . $resource['kind'] . " " . $resource['name'] . " in " . $resource['namespace'];
                    exit(1);
                }
            }
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