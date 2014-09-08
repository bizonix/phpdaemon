<?php
namespace PHPDaemon\FS;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Timer;

/**
 * Implementation of the file watcher
 * @package PHPDaemon\FS
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class FileWatcher {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var array @todo
	 */
	public $files = [];

	/**
	 * @var resource @todo
	 */
	public $inotify;

	/**
	 * @var array @todo
	 */
	public $descriptors = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		if (Daemon::loadModuleIfAbsent('inotify')) {
			$this->inotify = inotify_init();
			stream_set_blocking($this->inotify, 0);
		}

		Timer::add(function ($event) {

			Daemon::$process->fileWatcher->watch();
			if (sizeof(Daemon::$process->fileWatcher->files) > 0) {
				$event->timeout();
			}

		}, 1e6 * 1, 'fileWatcher');

	}

	/**
	 * Adds your subscription on object in FS
	 * @param  string  $path       @todo
	 * @param  mixed   $subscriber @todo
	 * @param  integer $flags      @todo
	 * @return true
	 */
	public function addWatch($path, $subscriber, $flags = NULL) {
		$path = realpath($path);
		if (!isset($this->files[$path])) {
			$this->files[$path] = [];
			if ($this->inotify) {
				$this->descriptors[inotify_add_watch($this->inotify, $path, $flags ? : IN_MODIFY)] = $path;
			}
		}
		$this->files[$path][] = $subscriber;
		Timer::setTimeout('fileWatcher');
		return true;
	}

	/**
	 * Cancels your subscription on object in FS
	 * @param  string  $path       @todo
	 * @param  mixed   $subscriber @todo
	 * @return boolean
	 */
	public function rmWatch($path, $subscriber) {

		$path = realpath($path);

		if (!isset($this->files[$path])) {
			return false;
		}
		if (($k = array_search($subscriber, $this->files[$path], true)) !== false) {
			unset($this->files[$path][$k]);
		}
		if (sizeof($this->files[$path]) === 0) {
			if ($this->inotify) {
				if (($descriptor = array_search($path, $this->descriptors)) !== false) {
					inotify_rm_watch($this->inotify, $descriptor);
				}
			}
			unset($this->files[$path]);
		}
		return true;
	}

	/**
	 * Called when file $path is changed
	 * @param  string $path @todo
	 * @return void
	 */
	public function onFileChanged($path) {
		if (!Daemon::lintFile($path)) {
			Daemon::log(__METHOD__ . ': Detected parse error in ' . $path);
			return;
		}
		foreach ($this->files[$path] as $subscriber) {
			if (is_callable($subscriber) || is_array($subscriber)) {
				call_user_func($subscriber, $path);
			}
			elseif (!Daemon::$process->IPCManager->importFile($subscriber, $path)) {
				$this->rmWatch($path, $subscriber);
			}
		}
	}

	/**
	 * Check the file system, triggered by timer
	 * @return void
	 */
	public function watch() {
		if ($this->inotify) {
			$events = inotify_read($this->inotify);
			if (!$events) {
				return;
			}
			foreach ($events as $ev) {
				$path = $this->descriptors[$ev['wd']];
				if (!isset($this->files[$path])) {
					continue;
				}
				$this->onFileChanged($path);
			}
		}
		else {
			static $hash = [];

			foreach (array_keys($this->files) as $path) {
				if (!file_exists($path)) {
					// file can be deleted
					unset($this->files[$path]);
					continue;
				}

				$mt = filemtime($path);

				if (
						isset($hash[$path])
						&& ($mt > $hash[$path])
				) {
					$this->onFileChanged($path);
				}

				$hash[$path] = $mt;
			}
		}
	}
}
