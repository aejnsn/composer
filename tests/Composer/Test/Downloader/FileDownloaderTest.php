<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Downloader;

use Composer\Downloader\FileDownloader;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;

class FileDownloaderTest extends TestCase
{
    protected function getDownloader($io = null, $config = null, $eventDispatcher = null, $cache = null, $rfs = null, $filesystem = null)
    {
        $io = $io ?: $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $config ?: $this->getMockBuilder('Composer\Config')->getMock();
        $rfs = $rfs ?: $this->getMockBuilder('Composer\Util\RemoteFilesystem')->disableOriginalConstructor()->getMock();

        return new FileDownloader($io, $config, $eventDispatcher, $cache, $rfs, $filesystem);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDownloadForPackageWithoutDistReference()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue(null))
        ;

        $downloader = $this->getDownloader();
        $downloader->download($packageMock, '/path');
    }

    public function testDownloadToExistingFile()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue('url'))
        ;
        $packageMock->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array('url')))
        ;

        $path = tempnam($this->getUniqueTmpDirectory(), 'c');
        $downloader = $this->getDownloader();

        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
            $this->assertInstanceOf('RuntimeException', $e);
            $this->assertContains('exists and is not a directory', $e->getMessage());
        }
    }

    public function testGetFileName()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        $downloader = $this->getDownloader();
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $this->assertEquals('/path/script.js', $method->invoke($downloader, $packageMock, '/path'));
    }

    public function testDownloadButFileIsUnsaved()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'http://example.com/script.js'))
        ;
        $packageMock->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)))
        ;
        $packageMock->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue(array()))
        ;

        $path = $this->getUniqueTmpDirectory();
        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function ($messages, $newline = true) use ($path) {
                if (is_file($path.'/script.js')) {
                    unlink($path.'/script.js');
                }

                return $messages;
            }))
        ;

        $downloader = $this->getDownloader($ioMock);
        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e);
            $this->assertContains('could not be saved to', $e->getMessage());
        }
    }

    public function testCacheGarbageCollectionIsCalled()
    {
        $expectedTtl = '99999999';

        $configMock = $this->getMockBuilder('Composer\Config')->getMock();
        $configMock
            ->expects($this->at(0))
            ->method('get')
            ->with('cache-files-ttl')
            ->will($this->returnValue($expectedTtl));
        $configMock
            ->expects($this->at(1))
            ->method('get')
            ->with('cache-files-maxsize')
            ->will($this->returnValue('500M'));

        $cacheMock = $this->getMockBuilder('Composer\Cache')
                     ->disableOriginalConstructor()
                     ->getMock();
        $cacheMock
            ->expects($this->any())
            ->method('gcIsNecessary')
            ->will($this->returnValue(true));
        $cacheMock
            ->expects($this->once())
            ->method('gc')
            ->with($expectedTtl, $this->anything());

        $downloader = $this->getDownloader(null, $configMock, null, $cacheMock, null, null);
    }

    public function testDownloadFileWithInvalidChecksum()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'http://example.com/script.js'))
        ;
        $packageMock->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue(array()))
        ;
        $packageMock->expects($this->any())
            ->method('getDistSha1Checksum')
            ->will($this->returnValue('invalid'))
        ;
        $packageMock->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)))
        ;
        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();

        $path = $this->getUniqueTmpDirectory();
        $downloader = $this->getDownloader(null, null, null, null, null, $filesystem);
        // make sure the file expected to be downloaded is on disk already
        touch($path.'/script.js');

        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e);
            $this->assertContains('checksum verification', $e->getMessage());
        }
    }

    public function testDowngradeShowsAppropriateMessage()
    {
        $oldPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $oldPackage->expects($this->once())
            ->method('getFullPrettyVersion')
            ->will($this->returnValue('1.2.0'));
        $oldPackage->expects($this->once())
            ->method('getVersion')
            ->will($this->returnValue('1.2.0.0'));

        $newPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $newPackage->expects($this->once())
            ->method('getFullPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $newPackage->expects($this->once())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $newPackage->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'http://example.com/script.js'));
        $newPackage->expects($this->once())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)));

        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->at(0))
            ->method('writeError')
            ->with($this->stringContains('Downgrading'));

        $path = $this->getUniqueTmpDirectory();
        touch($path.'/script.js');
        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $filesystem->expects($this->once())
            ->method('removeDirectory')
            ->will($this->returnValue(true));

        $downloader = $this->getDownloader($ioMock, null, null, null, null, $filesystem);
        $downloader->update($oldPackage, $newPackage, $path);
    }
}
