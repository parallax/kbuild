<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Miloske85\php_cli_table\Table as CliTable;
use DateTime;
use Exception;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\ServiceProvider;

class AfterDeploy {

    protected $afterDeploy;
    protected $kubeconfig;
    protected $taskSpooler;

    public function __construct($args) {
        $this->afterDeploy = $args['afterDeploy'];
        $this->kubeconfig = $args['kubeconfig'];
        $this->taskSpooler = $args['taskSpooler'];
    }

    public function delete() {
        
        $kubeConfig = $this->kubeconfig;
        $candidates = array();
        $return = array();

        // For each deletion block return all matching names
        foreach ($this->afterDeploy['delete'] as $key => $delete) {
            
            if (!isset($delete['namespace'])) {
                echo "Namespace not set in afterDeploy.delete in kbuild.yaml";
                exit(1);
            }

            $nameSpace = $delete['namespace'];
            $kind = $delete['kind'];

            // Get all of the candidates that are of kind that are in this namespace
            $allOfKind = json_decode(`kubectl --kubeconfig=$kubeConfig -n $nameSpace get $kind -o json`, true);

            foreach ($allOfKind['items'] as $key => $kind) {

                // Check each kind to see if it matches the pattern
                if (fnmatch($delete['namePattern'], $kind['metadata']['name'])) {

                    if (isset($delete['nameApartFrom'])) {
                        // Matches the forpattern, check if it doesn't match the apartFrom pattern
                        if (!fnmatch($delete['nameApartFrom'], $kind['metadata']['name'])) {
                            array_push($candidates, $kind['metadata']['name']);
                        }
                    }
                    else {
                         array_push($candidates, $kind['metadata']['name']);
                    }
                }
            }

            print_r($candidates);

            // Queue the deletions
            foreach ($candidates as $key => $name) {
                $this->taskSpooler->addJob('Delete ' . $delete['kind'] . ' ' . $name, "kubectl --kubeconfig=" . $kubeConfig . ' -n ' . $nameSpace . ' delete ' . $delete['kind'] . ' ' . $name);
            }

        }
    }
}