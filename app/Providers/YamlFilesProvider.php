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

class YamlFiles {

    protected $yamlDirectory;
    protected $yamlCount;
    protected $yamlFiles;
    protected $settings;
    protected $images;
    protected $yamlFileContents;
    protected $parsedYamlContents;
    protected $returnYamlContents;
    protected $kbuild;
    protected $environmentVariables;

    public function __construct($args) {
        $this->yamlDirectory = $args['yamlDirectory'];
        $this->yamlFileContents = array();

        // Config
        $this->settings['app'] = $args['app'];
        $this->settings['branch'] = $args['branch'];
        $this->settings['build'] = $args['build'];
        $this->settings['environment'] = $args['environment'];
        $this->images = $args['images'];
        $this->kbuild = $args['kbuild'];
        $this->settings['namespace'] = $args['app'] . '-' . $args['environment'];
        $this->taskSpooler = $args['taskSpooler'];
        $this->kubeconfig = $args['kubeconfig'];
        $this->environmentVariables = $args['environmentVariables'];

        $yamlFiles = scandir($this->yamlDirectory);
        $this->yamlFiles = $yamlFiles;
        $this->parsedYamlContents = array();

        // Filter for anything beginning with a .
        foreach ($yamlFiles as $key => $yamlFile) {
            if(strpos($yamlFile, '.') === 0) {
                unset($yamlFiles[$key]);
            }
            else {
                // Set the yamlFiles var that we push up in a bit
                $yamlFiles[$key] = $this->yamlDirectory . '/' . $yamlFiles[$key];

                // Split out the files into YAML blocks based on if they contain a separator
                $file = explode('---', file_get_contents($yamlFiles[$key]));
                
                // Foreach the file in case it contains more than one --- yaml file
                foreach ($file as $key => $contents) {
                    array_push($this->yamlFileContents, $contents);
                }

            }
        }

        // Read each $this->yamlFileContents and extract the kind so we can attempt to order them
        foreach ($this->yamlFileContents as $key => $contents) {
            $repeat = 'false';
            $contents = $this->findAndReplace($contents);

            // Check for any image: tags that need replacing
            preg_match_all('/{{ image.* }}/', $contents, $imageReplacement, PREG_PATTERN_ORDER);

            if (count($imageReplacement) > 0) {
                foreach ($imageReplacement[0] as $key => $replaceTag) {
                    $image = str_replace(' }}', '', str_replace('{{ image:', '', $replaceTag));

                    // Check if the image actually exists
                    if (!isset($this->images[$image])) {
                        echo "Docker image $image doesn't exist but is referenced in your yaml as {{ image:$image }}\n";
                        exit(1);
                    }

                    $contents = str_replace('{{ image:' . $image . ' }}', $this->images[$image], $contents);
                }
            }   

            // Check if this has extra metadata in it
            $contentsArray = Yaml::parse($contents);

            // If this is a deployment then add environment variables to it
            if ($contentsArray['kind'] === 'Deployment') {
                foreach ($contentsArray['spec']['template']['spec']['containers'] as $containerKey => $container) {
                    if (!isset($container['env'])) {
                        $contentsArray['spec']['template']['spec']['containers'][$containerKey]['env'] = array();
                    }

                    // Now push in the vars
                    foreach ($this->environmentVariables as $environmentKey => $environmentValue) {
                        array_push($contentsArray['spec']['template']['spec']['containers'][$containerKey]['env'],  array('name' => $environmentKey, 'value' => $environmentValue));
                    }
                }
                $contents = Yaml::dump($contentsArray, 50);
            }

            if (isset($contentsArray['repeat'])) {
                $repeat = $contentsArray['repeat'];
                if (isset($contentsArray['repeatOnTag'])) {
                    $repeatOnTag = $contentsArray['repeatOnTag'];
                    unset($contentsArray['repeatOnTag']);
                }
                else {
                    $repeatOnTag = '*';
                }
                unset($contentsArray['repeat']);

                // Cycle based on the different repeater types. Initially just eachDomain:
                if (null !== $this->kbuild) {

                    // Repeater on domain
                    if ($repeat === 'eachDomain') {
                        $domains = array();
                        foreach ($this->kbuild['domains'] as $key => $domain) {

                            // If this domain's settings match this build...
                            if ($domain['environments'] === '*' || $domain['environments'] === $this->settings['environment']) {
                                if ($domain['branches'] === '*' || $domain['branches'] === $this->settings['branch']) {
                                    if ($repeatOnTag === '*' || $repeatOnTag === $domain['tag']) {
                                        array_push($domains, $this->findAndReplace($domain['domain']));
                                    }
                                }
                            }
                        }

                        // For each of the domains that are relevant to this build, push the file
                        foreach ($domains as $key => $domain) {

                            $contentsToParse = str_replace('{{ domain }}', $domain, $contents);
                            $contentsToParse = str_replace('{{ domain_md5 }}', hash('md5', $domain), $contentsToParse);
                            $contentsArray = Yaml::parse($contentsToParse);
                            unset($contentsArray['repeat']);
                            unset($contentsArray['repeatOnTag']);
                            array_push($this->parsedYamlContents, array(
                                'kind'    => $contentsArray['kind'],
                                'repeat'        => $repeat,
                                'file'          => Yaml::dump($contentsArray, 50),
                            ));
                        }
                    }
                    // End repeater on domain
                }
            }

            else {
                $repeat = null;
                $contentsArray = Yaml::parse($contents);
                array_push($this->parsedYamlContents, array(
                    'kind'    => $contentsArray['kind'],
                    'repeat'        => $repeat,
                    'file'          => Yaml::dump($contentsArray, 50),
                ));
            }

        }

    }

    public function count () {
        return count($this->yamlFileContents);
    }

    public function getDomains () {
        $domains = array();
        foreach ($this->kbuild['domains'] as $key => $domain) {
            // If this domain's settings match this build...
            if ($domain['environments'] === '*' || $domain['environments'] === $this->settings['environment']) {
                if ($domain['branches'] === '*' || $domain['branches'] === $this->settings['branch']) {
                    array_push($domains, $this->findAndReplace($domain['domain']));
                }
            }             
        }
        return $domains;
    }

    public function getFiles () {

        $this->returnYamlContents = array();

        // Push bits in as they come
        $this->returnKinds($this->parsedYamlContents, 'Deployment');
        $this->returnKinds($this->parsedYamlContents, 'Ingress');
        $this->returnKinds($this->parsedYamlContents, 'Certificate');
        $this->returnKinds($this->parsedYamlContents, 'Service');

        return $this->returnYamlContents;
    }

    public function queue($kind) {
        $this->returnYamlContents = array();
        $this->returnKinds($this->parsedYamlContents, $kind);
        $deploymentCount = count($this->returnYamlContents);
        if ($deploymentCount > 0) {
            foreach ($this->returnYamlContents as $key => $deployment) {
                
                $hash = hash('md5', $deployment['file']);
                file_put_contents('/tmp/' . $hash, $deployment['file']);

                $dependency = $this->taskSpooler->addJob("$kind " . Yaml::parse($deployment['file'])['metadata']['name'], "kubectl --kubeconfig=" . $this->kubeconfig . " apply -f " . '/tmp/' . $hash);
                if ($kind === 'Deployment') {
                    $this->taskSpooler->addJob('Rollout deployment ' . Yaml::parse($deployment['file'])['metadata']['name'], "kubectl rollout status deployment --kubeconfig=" . $this->kubeconfig . " -n " . Yaml::parse($deployment['file'])['metadata']['namespace'] . " " . Yaml::parse($deployment['file'])['metadata']['name'], $dependency);
                }
            }
        }
    }

    private function returnKinds($parsedYamlContents, $kind) {
        $return = array();
        foreach ($parsedYamlContents as $key => $value) {
            if ($value['kind'] === $kind) {
                array_push($this->returnYamlContents, $value);
            }
        }
    }

    private function findAndReplace($yaml) {

        $yaml = str_replace('{{ app }}', $this->settings['app'], $yaml);
        $yaml = str_replace('{{ branch }}', $this->settings['branch'], $yaml);
        $yaml = str_replace('{{ build }}', $this->settings['build'], $yaml);
        $yaml = str_replace('{{ environment }}', $this->settings['environment'], $yaml);
        $yaml = str_replace('{{ namespace }}', $this->settings['namespace'], $yaml);

        return($yaml);

    }

    public function files () {
        return $this->yamlFiles;
    }

    public function fileContents () {
        return $this->yamlFileContents;
    }

}