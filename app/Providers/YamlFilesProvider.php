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
    protected $kbuild;

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

        // Read each $this->yamlFileContents and extract the apiVersions so we can attempt to order them
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

            $contentsArray = Yaml::parse($contents);

            // Check if this has extra metadata in it

            if (isset($contentsArray['repeat'])) {
                $repeat = $contentsArray['repeat'];
                unset($contentsArray['repeat']);

                // Cycle based on the different repeater types. Initially just eachDomain:
                if (null !== $this->kbuild) {

                    // Repeater on domain
                    if ($repeat === 'eachDomain') {
                        foreach ($this->kbuild['domains'] as $key => $domain) {

                            // Todo: find all of the instances of {{ domain }} and handle filtering by branch, environment etc
                            preg_match_all('/{{ domain.* }}/', $contents, $domainReplacement, PREG_PATTERN_ORDER);

                            
                        }
                    }
                }

            }

            else {
                array_push($this->parsedYamlContents, array(
                    'apiVersion'    => $contentsArray['apiVersion'],
                    'repeat'        => $repeat,
                    'file'          => Yaml::dump($contentsArray, 50),
                ));
            }

        }

        dd($this->parsedYamlContents);
    }

    public function count () {
        return count($this->yamlFileContents);
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