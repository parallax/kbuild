<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Spatie\Async\Pool as Pool;
use Spatie\Async\Task as Task;
use Spatie\Async\Process as Process;

class MyTask extends Task
{
    public function configure()
    {
        // Setup eg. dependency container, load config,...
    }

    public function run()
    {
        // Do the real work here.
    }
}

class Build extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build {kubeconfig : The kubeconfig file to use}';

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
        // Create the worker pool
        $pool = Pool::create()
            ->autoload(__DIR__ . '/../../vendor/autoload.php');

        // Check for async support
        if ($pool->isSupported() === TRUE) {
            $this->info('Asynchronous execution is supported on this platform');
        }
        else {
            $this->error('Asynchronous execution is not supported on this platform. Builds will run more slowly.');
        }
        
        $pool->add(new MyTask());
        
        $pool->wait();

        

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
