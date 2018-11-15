<?php

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
}