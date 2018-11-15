<?php

class MonitorConfiguratorTest extends \PHPUnit\Framework\TestCase
{
    use VladaHejda\AssertException;

    /**
     * @param string|Object $obj  Object
     * @param string        $name Method Name
     * @param array         $args
     *
     * @return mixed its based on method result;
     */
    public function callPrivateMethod($obj, $name, array $args = array())
    {
        $class  = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }

    public function testSetBaseDirectory()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory();
        $this->assertEquals('', $monitor_config->getBaseDirectory());

        $monitor_config->setBaseDirectory('__non_existant__');
        $this->assertEquals(getcwd() . '/' . '__non_existant__', $monitor_config->getBaseDirectory());

        $this->assertException(function () use (&$monitor_config) {
            $monitor_config->setBaseDirectory(__FILE__);
        }, Exception::class);

        $dir = '/tmp///1st/2nd/.//./3rd/../3rd1/../..////../';
        $monitor_config->setBaseDirectory($dir);
        $this->assertEquals('/tmp', $monitor_config->getBaseDirectory());
        $this->assertEquals('/tmp/', $monitor_config->getBaseDirectoryWithTrailingSlash());

        $monitor_config->setBaseDirectory('/');
        $this->assertEquals('/', $monitor_config->getBaseDirectory());
        $this->assertEquals('/', $monitor_config->getBaseDirectoryWithTrailingSlash());
    }

    public function testSetLevel()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory();
        $this->assertEquals(1, $monitor_config->getLevel());

        $this->assertEquals($monitor_config, $monitor_config->setLevel(0));
        $this->assertEquals(0, $monitor_config->getLevel());
        $this->assertEquals(3, $monitor_config->setLevel(3)->getLevel());
    }

    public function testSetFilesToMonitor()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory();
        $this->assertEquals([], $monitor_config->getFilesToMonitor());

        $this->assertEquals([], $monitor_config->setFilesToMonitor([])->getFilesToMonitor());
        $this->assertEquals([''], $monitor_config->setFilesToMonitor([''])->getFilesToMonitor());
        $this->assertEquals(['/tmp/', '*.yaml'],
          $monitor_config->setFilesToMonitor(['/tmp/', '*.yaml'])->getFilesToMonitor());
    }

    public function testOtherProperties()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory();
        $this->assertEquals(false, $monitor_config->isFireModifiedOnDirectories());

        $this->assertEquals(false, $monitor_config->setFireModifiedOnDirectories(false)->isFireModifiedOnDirectories());
        $this->assertEquals(true, $monitor_config->setFireModifiedOnDirectories(true)->isFireModifiedOnDirectories());


        $this->assertEquals(false, $monitor_config->isMonitorCreatedOnly());
        $this->assertEquals($monitor_config, $monitor_config->setMonitorCreatedOnly(false));
        $this->assertEquals($monitor_config, $monitor_config->setMonitorCreatedOnly(true));
        $this->assertEquals(true, $monitor_config->isMonitorCreatedOnly());

        $this->assertEquals(false, $monitor_config->isAutoCreateNotFoundMonitor());
        $this->assertEquals($monitor_config, $monitor_config->setAutoCreateNotFoundMonitor(false));
        $this->assertEquals($monitor_config, $monitor_config->setAutoCreateNotFoundMonitor(true));
        $this->assertEquals(true, $monitor_config->isAutoCreateNotFoundMonitor());

        $monitor_config
          ->setBaseDirectory('/etc')
          ->setLevel(5)
          ->setFilesToMonitor([
            'nginx/*.conf',    # Will monitor and matches files: '/etc/nginx/*.conf', this pattern constains
              # a slash and is not recursive.
          ]);
        $this->assertEquals(5, $monitor_config->getLevel());
        $this->assertEquals(2, $monitor_config->getEffectiveLevel());

        $monitor_config
          ->setFilesToMonitor([
            'apache/*.conf',
            '*.xnini',
          ]);
        $this->assertEquals(5, $monitor_config->getLevel());
        $this->assertEquals(5, $monitor_config->getEffectiveLevel());

        $monitor_config
          ->setLevel(0);

        $this->assertEquals(0, $monitor_config->getLevel());
        $this->assertEquals(0, $monitor_config->getEffectiveLevel());

        $monitor_config
          ->setFilesToMonitor([
            'apache/*.conf',
          ]);
        $this->assertEquals(2, $monitor_config->getEffectiveLevel());
    }

    public function testMatchers()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory()
          ->setBaseDirectory('/tmp')
          ->setLevel(1)
          ->setFilesToMonitor(['*.yaml']);

        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/etc'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/bar'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/etc/mysql.yaml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/go.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.yaml2'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.xml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/foo/go.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/foo/bar/go.yaml'));

        $monitor_config->setLevel(2);
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp'));
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/bar'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/go.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.yaml2'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.xml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/foo/go.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/foo/bar/go.yaml'));

        $monitor_config->setLevel(3);
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp'));
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo'));
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/bar'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/go.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.yaml2'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.xml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/foo/go.yaml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/foo/bar/go.yaml'));

        $monitor_config->setBaseDirectory('/')->setLevel(2);
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/bar'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/go.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.yaml2'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/go.xml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/foo/go.yaml'));


        $monitor_config
          ->setBaseDirectory('/tmp')
          ->setLevel(3)
          ->setFilesToMonitor([
            'dir1/*.xml',
            'dir1/dir2/file1.xml',
            '*/*.yaml',
            '*/d*/*/*/*/*.py',
          ]);
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/'));
        $patterns   = $monitor_config->getFilesToMonitor();
        $patterns[] = '';
        $monitor_config->setFilesToMonitor($patterns);
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/'));

        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/file.xml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/file.yaml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/dir1/file.xml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/dir1/file.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/dirx/dir1/file.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/dir1/dir2/file.yaml'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/dir1/dir2/file.xml'));
        $this->assertEquals(true, $monitor_config->isPathMatchMonitor('/tmp/dir3/f.yaml'));

        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/dir1/dir2/dir3/dir4/dir5/f.py'));
        $this->assertEquals(true,
          $monitor_config->setLevel(6)->isPathMatchMonitor('/tmp/dir1/dir2/dir3/dir4/dir5/f.py'));
        $this->assertEquals(false, $monitor_config->isPathMatchMonitor('/tmp/dir1/dir2/file.yaml'));

        $this->assertEquals(false, $this->callPrivateMethod($monitor_config, 'isStringMatch', ['file.yaml']));
        $this->assertEquals(true, $this->callPrivateMethod($monitor_config, 'isStringMatch', ['dir13/f.yaml']));
        $this->assertEquals(false, $this->callPrivateMethod($monitor_config, 'isStringMatch', ['dir1/dir2/f.yaml']));

        //
        $monitor_config
          ->setBaseDirectory('/tmp')
          ->setLevel(3)
          ->setFilesToMonitor([
            '/dir1/*.xml',
            '/*/dir2/*.yaml',
            '*/d*/*/*/*/*.py',
          ]);

        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp'));
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/bar'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/bar/baz'));
        $this->assertEquals(false, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/foo/d2'));
        $this->assertEquals(true, $monitor_config->mayDirectoryContainMonitoredItems('/tmp/dir1/dir2'));


    }

    public function testOtherMethods()
    {
        $monitor_config = \Dimsh\React\Filesystem\Monitor\MonitorConfigurator::factory();
        $this->assertEquals('/tmp/', $monitor_config->dirPath('/tmp/'));
        $this->assertEquals('/tmp/', $monitor_config->dirPath('/tmp'));
        $this->assertEquals('__non_existant__/', $monitor_config->dirPath('__non_existant__/'));
        $this->assertEquals('__non_existant__/', $monitor_config->dirPath('__non_existant__'));
        $this->assertEquals('/__non_existant__/', $monitor_config->dirPath('/__non_existant__/'));
        $this->assertEquals('/__non_existant__/', $monitor_config->dirPath('/__non_existant__'));

        $this->assertEquals(true, $monitor_config->hasTrailingSlash('/tmp/'));
        $this->assertEquals(false, $monitor_config->hasTrailingSlash('/tmp'));
        $this->assertEquals(true, $monitor_config->hasTrailingSlash('__non_existant__/'));
        $this->assertEquals(false, $monitor_config->hasTrailingSlash('__non_existant__'));

        $this->assertEquals(false, $monitor_config->hasTrailingSlash('__non_existant__'));

    }
}
