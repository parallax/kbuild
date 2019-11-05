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
        {--kubeconfig= : The path to the kubeconfig file}
        {--delete : Actually delete stuff rather than just printing}';

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

        if (false !== $this->option('delete')) {
            $this->info("Actually deleting things as the --delete flag was passed");
        }

        else {
            $this->info("Not deleting anything as --delete was not passed");
        }

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

        // Statefulsets

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get cronjobs --all-namespaces -o json";

        $cronjobs = shell_exec($command);

        $cronjobs = json_decode($cronjobs, TRUE);

        foreach ($cronjobs['items'] as $key => $cronjob) {

            if (isset($cronjob['metadata']['annotations']['ttl']) && $cronjob['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $cronjob['metadata']['name'],
                    'namespace' => $cronjob['metadata']['namespace'],
                    'kind' => $cronjob['kind'],
                    'ttl' => $cronjob['metadata']['annotations']['ttl']
                ));
            }

        }

        // HPA

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get hpa --all-namespaces -o json";

        $hpas = shell_exec($command);

        $hpas = json_decode($hpas, TRUE);

        foreach ($hpas['items'] as $key => $hpa) {

            if (isset($hpa['metadata']['annotations']['ttl']) && $hpa['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $hpa['metadata']['name'],
                    'namespace' => $hpa['metadata']['namespace'],
                    'kind' => $hpa['kind'],
                    'ttl' => $hpa['metadata']['annotations']['ttl']
                ));
            }

        }

        // Certificates

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get certificates --all-namespaces -o json";

        $certificates = shell_exec($command);

        $certificates = json_decode($certificates, TRUE);

        foreach ($certificates['items'] as $key => $certificate) {

            if (isset($certificate['metadata']['annotations']['ttl']) && $certificate['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $certificate['metadata']['name'],
                    'namespace' => $certificate['metadata']['namespace'],
                    'kind' => $certificate['kind'],
                    'ttl' => $certificate['metadata']['annotations']['ttl']
                ));
            }

        }

        // Services

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get services --all-namespaces -o json";

        $services = shell_exec($command);

        $services = json_decode($services, TRUE);

        foreach ($services['items'] as $key => $service) {

            if (isset($service['metadata']['annotations']['ttl']) && $service['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $service['metadata']['name'],
                    'namespace' => $service['metadata']['namespace'],
                    'kind' => $service['kind'],
                    'ttl' => $service['metadata']['annotations']['ttl']
                ));
            }

        }

        // Ingresses

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get ing --all-namespaces -o json";

        $ingresses = shell_exec($command);

        $ingresses = json_decode($ingresses, TRUE);

        foreach ($ingresses['items'] as $key => $ingress) {

            if (isset($ingress['metadata']['annotations']['ttl']) && $ingress['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $ingress['metadata']['name'],
                    'namespace' => $ingress['metadata']['namespace'],
                    'kind' => $ingress['kind'],
                    'ttl' => $ingress['metadata']['annotations']['ttl']
                ));
            }

        }

        // Traefik Ingresses

        $command = "kubectl --kubeconfig=" . $this->option('kubeconfig') . " get IngressRoute --all-namespaces -o json";

        $traefikIngresses = shell_exec($command);

        $traefikIngresses = json_decode($traefikIngresses, TRUE);

        foreach ($traefikIngresses['items'] as $key => $ingress) {

            if (isset($ingress['metadata']['annotations']['ttl']) && $ingress['metadata']['annotations']['ttl'] < date("U"))
            {
                array_push($resources, array(
                    'name' => $ingress['metadata']['name'],
                    'namespace' => $ingress['metadata']['namespace'],
                    'kind' => $ingress['kind'],
                    'ttl' => $ingress['metadata']['annotations']['ttl']
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

        if (false !== $this->option('delete')) {
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