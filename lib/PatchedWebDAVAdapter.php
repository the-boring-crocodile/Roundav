<?php

use League\Flysystem\Config;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\WebDAV\WebDAVAdapter;

/**
 * Patched WebDAVAdapter that fixes the createDirectory bug.
 *
 * Upstream PR: https://github.com/thephpleague/flysystem/pull/1852
 *
 * Two bugs in the original createDirectory():
 *  1. Empty path segments (from leading slash) trigger `return`, aborting the loop entirely.
 *  2. $location is missing a leading slash, causing MKCOL requests to fail.
 *
 * Because $client and $prefixer are private in the parent, we access them via ReflectionClass.
 */
class PatchedWebDAVAdapter extends WebDAVAdapter
{
    /**
     * The subfolders of a directory, with how many folders each contains where the
     * server reports it (Nextcloud: nc:contained-folder-count), in one PROPFIND.
     *
     * @param string $path a normalized path, as Filesystem passes to listContents()
     * @return array<array-key,int|null> path (as listContents() gives it; a numeric
     *                                   name becomes an int key) => folder count or null
     */
    public function listDirectories(string $path): array
    {
        $reflection = new ReflectionClass(WebDAVAdapter::class);
        $prefixer = $reflection->getProperty('prefixer')->getValue($this);
        $client   = $reflection->getProperty('client')->getValue($this);

        $count    = '{http://nextcloud.org/ns}contained-folder-count';
        $location = $this->encodePath($prefixer->prefixDirectoryPath($path));
        $response = $client->propFind($location, array('{DAV:}resourcetype', '{DAV:}iscollection', $count), 1);

        // The directory itself comes first.
        array_shift($response);

        $folders = array();
        foreach ($response as $href => $props) {
            // A folder as Flysystem decides it (WebDAVAdapter::propsIsDirectory()).
            $type = $props['{DAV:}resourcetype'] ?? null;
            $isDir = $type instanceof \Sabre\DAV\Xml\Property\ResourceType
                ? $type->is('{DAV:}collection')
                : ($props['{DAV:}iscollection'] ?? null) === '1';
            if (!$isDir) {
                continue;
            }
            $child = $prefixer->stripPrefix((string) parse_url(rawurldecode($href), PHP_URL_PATH));
            $folders[trim($child, '/')] = isset($props[$count]) && is_numeric($props[$count]) ? (int) $props[$count] : null;
        }

        return $folders;
    }

    public function createDirectory(string $path, Config $config): void
    {
        $reflection = new ReflectionClass(WebDAVAdapter::class);

        $prefixerProp = $reflection->getProperty('prefixer');
        $clientProp   = $reflection->getProperty('client');

        // setAccessible() is required before PHP 8.1; deprecated (no-op) from 8.1 onward.
        if (PHP_VERSION_ID < 80100) {
            $prefixerProp->setAccessible(true);
            $clientProp->setAccessible(true);
        }

        $prefixer = $prefixerProp->getValue($this);
        $client   = $clientProp->getValue($this);

        $parts = explode('/', $prefixer->prefixDirectoryPath($path));
        $directoryParts = [];

        foreach ($parts as $directory) {
            if ($directory === '.' || $directory === '') {
                continue; // fix #1: was `return`, which aborted the entire method
            }

            $directoryParts[] = $directory;
            $directoryPath = implode('/', $directoryParts);
            $location = '/' . $this->encodePath($directoryPath) . '/'; // fix #2: added leading '/'

            if ($this->directoryExists($prefixer->stripDirectoryPrefix($location))) {
                continue;
            }

            try {
                $response = $client->request('MKCOL', $location);
            } catch (Throwable $exception) {
                throw UnableToCreateDirectory::dueToFailure($path, $exception);
            }

            if ($response['statusCode'] !== 201) {
                throw UnableToCreateDirectory::atLocation($path, 'Failed to create directory at: ' . $location . ': ' . $response['body']);
            }
        }
    }
}
