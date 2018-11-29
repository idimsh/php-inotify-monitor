<?php

use Dimsh\React\Filesystem\Monitor\Monitor;

class MonitorTest extends \PHPUnit\Framework\TestCase
{
    public function testSetup()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory()
          ->setBaseDirectory('/tmp')
          ->setAutoCreateNotFoundMonitor(false)
          ->setLevel(1)
          ->setFilesToMonitor([
            '*.yaml',
          ]);

        $this->assertInstanceOf(\Dimsh\React\Filesystem\Monitor\Monitor::class,
          $monitor = new \Dimsh\React\Filesystem\Monitor\Monitor($monitor_config));
        $this->assertInstanceOf(\React\EventLoop\LoopInterface::class, $monitor->getEventLoop());

        unset($monitor);
        $loop = \React\EventLoop\Factory::create();
        $this->assertInstanceOf(\Dimsh\React\Filesystem\Monitor\Monitor::class,
          $monitor = new \Dimsh\React\Filesystem\Monitor\Monitor($monitor_config, $loop));
        $this->assertInstanceOf(\React\EventLoop\LoopInterface::class, $monitor->getEventLoop());
        $this->assertEquals($loop, $monitor->getEventLoop());

        unset($monitor);
        $monitor_config->setBaseDirectory('__not_exists__')->setAutoCreateNotFoundMonitor(true);
        $this->assertInstanceOf(\Dimsh\React\Filesystem\Monitor\Monitor::class,
          $monitor = new \Dimsh\React\Filesystem\Monitor\Monitor($monitor_config));

        unset($monitor);
        $monitor_config->setAutoCreateNotFoundMonitor(false);
        $this->assertInstanceOf(\Dimsh\React\Filesystem\Monitor\Monitor::class,
          $monitor = new \Dimsh\React\Filesystem\Monitor\Monitor($monitor_config));

        $this->assertEquals(null, $monitor->run());
    }

    public function testFireEvents()
    {
        $base_directory = '/tmp/___non___existant___dir___';

        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory()
          ->setLevel(3)
          ->setAutoCreateNotFoundMonitor(true)
                    ->setFireModifiedOnDirectories(true)
          ->setFilesToMonitor([
            '*',
            '',
            'dir1-at-lev1/',
            '/stop-if-created/',
          ]);
        try {
            $monitor_config->setBaseDirectory($base_directory);
        } catch (\Exception $e) {
            $this->fail("exception thrown: [{$e->getMessage()}]");
        }

        $list_of_created_items =
        $list_of_modified_items =
        $list_of_deleted_items = [];

        $monitor = new Monitor($monitor_config);
        $monitor
          ->on(Monitor::EV_CREATE, function ($path, $monitor) use (&$list_of_created_items, $base_directory) {
              /** @var Monitor $monitor */

              $list_of_created_items[] = $path;
              fputs(STDERR, "created:   $path\n");
              if ($path === "$base_directory/stop-if-created/") {
                  fputs(STDERR, "stopping ... \n");
                  $monitor->stop();
              }
          })
          ->on(Monitor::EV_MODIFY, function ($path, $monitor) use (&$list_of_modified_items) {
              $list_of_modified_items[] = $path;
              fputs(STDERR, "modified:  $path\n");
          })
          ->on(Monitor::EV_DELETE, function ($path, $monitor) use (&$list_of_deleted_items) {
              $list_of_deleted_items[] = $path;
              fputs(STDERR, "deleted:   $path\n");
          });

        $creator_runner_script = __DIR__ . '/helpers/creator-background-runner.sh';

        $pid = exec('bash ' . $creator_runner_script);

        if ($pid && is_numeric($pid)) {
            // this will block until stop is called in the "oncreate" event handler.
            $monitor->run();

            $this->assertEquals($list_of_created_items, [
              "$base_directory/",
              "$base_directory/file1-at-lev1",
              "$base_directory/dir1-at-lev1/",
              "$base_directory/dir1-at-lev1/file11-at-lev2",
              "$base_directory/dir1-at-lev1/dir11-at-lev2/file111-at-lev3",
              "$base_directory/dir3-at-lev1/dir11-at-lev2/file111-at-lev3",
              "$base_directory/dir3-at-lev1/file11-at-lev2",
              "$base_directory/dir2-at-lev1/file21-at-lev2",
              "$base_directory/stop-if-created/",
            ]);

            $this->assertEquals($list_of_modified_items, [
              "$base_directory/file1-at-lev1",
              "$base_directory/dir1-at-lev1/",
            ]);

            $this->assertEquals($list_of_deleted_items, [
              "$base_directory/dir1-at-lev1/dir11-at-lev2/file111-at-lev3",
              "$base_directory/dir1-at-lev1/file11-at-lev2",
              "$base_directory/dir1-at-lev1/",
              "$base_directory/dir3-at-lev1/dir11-at-lev2/file111-at-lev3",
              "$base_directory/dir3-at-lev1/file11-at-lev2",
            ]);

        } else {
            $this->fail('can not run the creator runner script');
        }
    }
}
