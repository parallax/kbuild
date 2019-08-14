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

class SSLGarbageCollection extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'sslgarbage 
        {--kubeconfig= : The path to the kubeconfig file}
        {--delete : Actually delete stuff rather than just printing}
        {--age= : The age (in hours) of the certificates to consider for deletion}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Collect and delete SSL certificates that are older than --age';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        if (false !== $this->option('delete')) {
            $this->info("Actually deleting things as the --delete flag was passed");
        }

        else {
            $this->info("Not deleting anything as --delete was not passed");
        }

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get certificates --all-namespaces -o json";

        $certificates = shell_exec($command);

        $certificates = json_decode($certificates, TRUE);

        $this->info("Found " . count($certificates['items']) . ' certificates');

        $resources = array();

        foreach ($certificates['items'] as $key => $certificate) {

            foreach ($certificate['status']['conditions'] as $conditonKey => $condition) {
                if ($condition['type'] === 'Ready' && $condition['status'] === 'False') {
                    if (date('U', strtotime($certificate['metadata']['creationTimestamp'])) < (date('U') - 3600 * $this->option('age'))) {

                        array_push($resources, array(
                            'name' => $certificate['metadata']['name'],
                            'namespace' => $certificate['metadata']['namespace'],
                            'domains' => implode(', ', $certificate['spec']['dnsNames'])
                        ));
                    }
                }
            }
        }

        $this->info("Found " . count($resources) . ' certificates to be deleted');

        $this->table(
            // Headers
            [
                'Name',
                'Namespace',
                'Domains'
            ],
            // Data
            $resources
        );

        if (false !== $this->option('delete')) {
            // Deletions
            foreach ($resources as $key => $resource) {
                if (isset($resource['name']) && isset($resource['namespace'])) {
    
                    $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " delete " . 'certificate' . " -n " . $resource['namespace'] . " " . $resource['name'];
    
                    system($command, $exit);
                    if ($exit !== 0) {
                        echo "Error deleting " . 'certificate' . " " . $resource['name'] . " in " . $resource['namespace'];
                        exit(1);
                    }
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