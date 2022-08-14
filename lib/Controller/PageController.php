<?php
/**
 * Nextcloud - gpxpod
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2015
 */

namespace OCA\GpxPod\Controller;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OC\Files\Node\File;
use OCA\GpxPod\AppInfo\Application;

use OCA\GpxPod\Db\Directory;
use OCA\GpxPod\Db\DirectoryMapper;
use OCA\GpxPod\Db\TrackMapper;
use OCA\GpxPod\Service\ConversionService;
use OCA\GpxPod\Service\ProcessService;
use OCA\GpxPod\Service\ToolsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IConfig;
use phpGPX\Models\Point;
use phpGPX\Models\Segment;
use phpGPX\Models\Track;
use phpGPX\phpGPX;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\DB\QueryBuilder\IQueryBuilder;

require_once __DIR__ . '/../../vendor/autoload.php';

class PageController extends Controller {

	private $userfolder;
	private $userId;
	private $config;
	private $dbconnection;
	private $extensions;
	private $upperExtensions;
	protected $appName;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;
	/**
	 * @var ProcessService
	 */
	private $processService;
	/**
	 * @var ConversionService
	 */
	private $conversionService;
	/**
	 * @var ToolsService
	 */
	private $toolsService;
	/**
	 * @var DirectoryMapper
	 */
	private $directoryMapper;
	/**
	 * @var TrackMapper
	 */
	private $trackMapper;

	public function __construct($appName,
								IRequest $request,
								IConfig $config,
								IInitialState $initialStateService,
								IRootFolder $root,
								IDBConnection $dbconnection,
								IClientService $clientService,
								ProcessService $processService,
								ConversionService $conversionService,
								ToolsService $toolsService,
								DirectoryMapper $directoryMapper,
								TrackMapper $trackMapper,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->appName = $appName;
		$this->userId = $userId;
		$this->root = $root;
		$this->client = $clientService->newClient();
		if ($userId !== null && $userId !== ''){
			$this->userfolder = $this->root->getUserFolder($userId);
		}
		$this->config = $config;
		$this->dbconnection = $dbconnection;

		$this->extensions = [
			'.kml' => 'kml',
			'.gpx' => '',
			'.tcx' => 'gtrnctr',
			'.igc' => 'igc',
			'.jpg' => '',
			'.fit' => 'garmin_fit',
		];
		$this->upperExtensions = array_map('strtoupper', array_keys($this->extensions));
		$this->initialStateService = $initialStateService;
		$this->processService = $processService;
		$this->conversionService = $conversionService;
		$this->toolsService = $toolsService;
		$this->directoryMapper = $directoryMapper;
		$this->trackMapper = $trackMapper;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $service
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @return DataDisplayResponse
	 * @throws Exception
	 */
	public function getRasterTile(string $service, int $x, int $y, int $z): DataDisplayResponse {
		if ($service === 'osm') {
			$s = 'abc'[mt_rand(0, 2)];
			$url = 'https://' . $s . '.tile.openstreetmap.org/' . $z . '/' . $x . '/' . $y . '.png';
		} elseif ($service === 'esri-topo') {
			$url = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/' . $z . '/' . $y . '/' . $x;
		} elseif ($service === 'watercolor') {
			$s = 'abc'[mt_rand(0, 2)];
			$url = 'http://' . $s . '.tile.stamen.com/watercolor/' . $z . '/' . $x . '/' . $y . '.jpg';
		} else {
			$s = 'abc'[mt_rand(0, 2)];
			$url = 'https://' . $s . '.tile.openstreetmap.org/' . $z . '/' . $x . '/' . $y . '.png';
		}
		try {
			$response = new DataDisplayResponse($this->client->get($url)->getBody());
			$response->cacheFor(60 * 60 * 24);
			return $response;
		} catch (ClientException | ServerException $e) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function index(): TemplateResponse {
		$this->cleanDbFromAbsentFiles($this->userId, null);
		$alldirs = $this->getDirectories($this->userId);

		// personal settings
		$settings = [];
		$keys = $this->config->getUserKeys($this->userId, Application::APP_ID);
		foreach ($keys as $key) {
			$value = $this->config->getUserValue($this->userId, Application::APP_ID, $key);
			$settings[$key] = $value;
		}

		$settings['app_version'] = $this->config->getAppValue(Application::APP_ID, 'installed_version');

		$adminMaptileApiKey = $this->config->getAppValue(Application::APP_ID, 'maptiler_api_key', Application::DEFAULT_MAPTILER_API_KEY) ?: Application::DEFAULT_MAPTILER_API_KEY;
		$maptilerApiKey = $this->config->getUserValue($this->userId, Application::APP_ID, 'maptiler_api_key', $adminMaptileApiKey) ?: $adminMaptileApiKey;
		$settings['maptiler_api_key'] = $maptilerApiKey;
		$adminMapboxApiKey = $this->config->getAppValue(Application::APP_ID, 'mapbox_api_key', Application::DEFAULT_MAPBOX_API_KEY) ?: Application::DEFAULT_MAPBOX_API_KEY;
		$mapboxApiKey = $this->config->getUserValue($this->userId, Application::APP_ID, 'mapbox_api_key', $adminMapboxApiKey) ?: $adminMapboxApiKey;
		$settings['mapbox_api_key'] = $mapboxApiKey;

		// for vue reactive props, initialize missing ones that have an immediate effect on the map
		if (!isset($settings['nav_tracks_filter_map_bounds'])) {
			$settings['nav_tracks_filter_map_bounds'] = '';
		}
		if (!isset($settings['show_mouse_position_control'])) {
			$settings['show_mouse_position_control'] = '';
		}
		if (!isset($settings['use_terrain'])) {
			$settings['use_terrain'] = '';
		}

		$dirObj = [];
		foreach ($alldirs as $dir) {
			$dirObj[$dir['id']] = [
				'id' => $dir['id'],
				'path' => $dir['path'],
				'isOpen' => $dir['isOpen'],
				'sortOrder' => $dir['sortOrder'],
				'tracks' => [],
				'loading' => false,
			];
		}

		$state = [
			'directories' => $dirObj,
			'settings' => $settings,
		];
		$this->initialStateService->provideInitialState(
			'gpxpod-state',
			$state
		);

		$response = new TemplateResponse(Application::APP_ID, 'newMain');
		$csp = new ContentSecurityPolicy();
		// tiles
		$csp->addAllowedImageDomain('https://*.tile.openstreetmap.org');
		$csp->addAllowedImageDomain('https://api.maptiler.com');

		$csp->addAllowedConnectDomain('https://api.maptiler.com');
		$csp->addAllowedConnectDomain('https://api.mapbox.com');
		$csp->addAllowedConnectDomain('https://events.mapbox.com');
		// TODO check why this is needed (maybe only for NC < 25)
		$csp->addAllowedChildSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param bool $isOpen
	 * @param int|null $sortOrder
	 * @return DataResponse
	 * @throws \OCP\DB\Exception
	 */
	public function updateDirectory(int $id, ?bool $isOpen = null, ?int $sortOrder = null): DataResponse {
		$this->directoryMapper->updateDirectory($id, $this->userId, null, $isOpen, $sortOrder);
		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param bool|null $isEnabled
	 * @param string|null $color
	 * @param int|null $colorCriteria
	 * @return DataResponse
	 * @throws \OCP\DB\Exception
	 */
	public function updateTrack(int $id, ?bool $isEnabled = null, ?string $color = null, ?int $colorCriteria = null): DataResponse {
		$this->trackMapper->updateTrack($id, $this->userId, null, null, $isEnabled, $color, $colorCriteria);
		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 * @param string $path
	 * @param bool $recursive
	 * @return DataResponse
	 * @throws \OCP\DB\Exception
	 */
	public function addDirectory(string $path, bool $recursive = false): DataResponse {
		if ($recursive) {
			return $this->addDirectoryRecursive($path);
		}
		$userFolder = $this->userfolder;

		$cleanpath = str_replace(['../', '..\\'], '', $path);
		if ($userFolder->nodeExists($cleanpath)) {
			try {
				$dir = $this->directoryMapper->createDirectory($cleanpath, $this->userId, false);
				$addedId = $dir->getId();
			} catch (\OCP\DB\Exception $e) {
				return new DataResponse('Impossible to insert. ' . $e->getMessage(), 400);
			}
			return new DataResponse($addedId);
		} else {
			return new DataResponse($cleanpath . ' does not exist', 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function addDirectoryRecursive(string $path): DataResponse {
		$userFolder = $this->userfolder;
		$userfolder_path = $userFolder->getPath();
		$qb = $this->dbconnection->getQueryBuilder();

		$cleanpath = str_replace(['../', '..\\'], '',  $path);
		if ($userFolder->nodeExists($cleanpath)) {
			$folder = $userFolder->get($cleanpath);

			// DIRS array population
			$optionValues = $this->processService->getSharedMountedOptionValue($this->userId);
			$sharedAllowed = $optionValues['sharedAllowed'];
			$mountedAllowed = $optionValues['mountedAllowed'];
			$showpicsonlyfold = $this->config->getUserValue($this->userId, 'gpxpod', 'showpicsonlyfold', 'true');
			$searchJpg = ($showpicsonlyfold === 'true');
			$extensions = array_keys($this->extensions);
			if ($searchJpg) {
				$extensions = array_merge($extensions, ['.jpg']);
			}
			$files = $this->processService->searchFilesWithExt($folder, $sharedAllowed, $mountedAllowed, $extensions);
			$alldirs = [];
			foreach($files as $file) {
				if ($file->getType() === \OCP\Files\FileInfo::TYPE_FILE and
					// name extension is supported
					(
						in_array( '.'.pathinfo($file->getName(), PATHINFO_EXTENSION), array_keys($this->extensions)) or
						in_array( '.'.pathinfo($file->getName(), PATHINFO_EXTENSION), $this->upperExtensions)
					)
				) {
					$rel_dir = str_replace($userfolder_path, '', dirname($file->getPath()));
					$rel_dir = str_replace('//', '/', $rel_dir);
					if ($rel_dir === '') {
						$rel_dir = '/';
					}
					if (!in_array($rel_dir, $alldirs)) {
						$alldirs[] = $rel_dir;
					}
				}
			}

			// add each directory
			$addedDirs = [];
			foreach ($alldirs as $path) {
				try {
					$insertedDir = $this->directoryMapper->createDirectory($path, $this->userId, false);
					$addedDirs[] = $insertedDir->jsonSerialize();
				} catch (\OCP\DB\Exception $e) {
					// ignore this dir
				}
			}
			return new DataResponse($addedDirs);
		} else {
			return new DataResponse($cleanpath . ' does not exist', 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * @param int $id
	 * @return DataResponse
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteDirectory(int $id): DataResponse {
		try {
			$dir = $this->directoryMapper->getDirectoryOfUser($id, $this->userId);
		} catch (DoesNotExistException $e) {
			return new DataResponse('Directory not found', Http::STATUS_BAD_REQUEST);
		}
		$this->directoryMapper->delete($dir);
		$this->trackMapper->deleteDirectoryTracksForUser($this->userId, $id);
		return new DataResponse('');
	}

	public function getDirectories(string $userId): array {
		return array_map(static function(Directory $directory) {
			return $directory->jsonSerialize();
		}, $this->directoryMapper->getDirectoriesOfUser($userId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function getGeojson(string $path): DataResponse {
		$userFolder = $this->userfolder;

		$cleanpath = str_replace(['../', '..\\'], '',  $path);
		if ($userFolder->nodeExists($cleanpath)) {
			$file = $userFolder->get($cleanpath);
			if ($file->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
				if ($this->toolsService->endswith($file->getName(), '.GPX') || $this->toolsService->endswith($file->getName(), '.gpx')) {
					$gpxContent = $this->toolsService->remove_utf8_bom($file->getContent());
					$geojsonArray = $this->gpxToGeojson($gpxContent);
					return new DataResponse($geojsonArray);
				}
			}
		}

		return new DataResponse('', Http::STATUS_BAD_REQUEST);
	}

	private function gpxToGeojson(string $gpxContent): array {
		$gpx = new phpGPX();
		$gpxArray = $gpx->parse($gpxContent);

		// one LineString per segment, ignoring the track separation
		/*
		$result = [
			'type' => 'FeatureCollection',
			'features' => [],
		];
		foreach ($gpxArray->tracks as $track) {
			foreach ($track->segments as $segment) {
				$result['features'][] = [
					'type' => 'Feature',
					'geometry' => [
						'type' => 'LineString',
    					'coordinates' => array_map(static function(Point $point) {
								return [
									$point->longitude,
									$point->latitude,
									$point->elevation,
									$point->time->getTimestamp(),
								];
							}, array_values(array_filter($segment->points, static function(Point $point) {
								return $point->longitude !== null && $point->latitude !== null && $point->time !== null;
							}))
						),
					],
				];
			}
		}
		return $result;
		*/

		// one multiline per gpx-track
		// one series of coords per gpx-segment
		return [
			'type' => 'FeatureCollection',
			'features' => array_map(static function(Track $track) {
				return [
					'type' => 'Feature',
					'geometry' => [
						'type' => 'MultiLineString',
    					'coordinates' => array_map(static function(Segment $segment) {
							return array_map(static function(Point $point) {
								return [
									$point->longitude,
									$point->latitude,
									$point->elevation,
									$point->time !== null ? $point->time->getTimestamp() : null,
								];
							}, array_values(array_filter($segment->points, static function(Point $point) {
								// && $point->time !== null;
								return $point->longitude !== null && $point->latitude !== null;
							})));
						}, $track->segments)
					],
					'properties' => [
						'name' => $track->name,
					],
				];
			}, $gpxArray->tracks),
		];
	}

	/**
	 * Ajax markers json retrieval from DB
	 *
	 * First convert kml, tcx... files if necessary.
	 * Then copy files to a temporary directory (decrypt them if necessary).
	 * Then correct elevations if it was asked.
	 * Then process the files to produce marker content.
	 * Then INSERT or UPDATE the database with processed data.
	 * Then get the markers for all gpx files in the target folder
	 * Then clean useless database entries (for files that no longer exist)
	 *
	 * @NoAdminRequired
	 */
	public function getTrackMarkersJson(int $id, string $directoryPath, bool $processAll = false): DataResponse {
		try {
			$dbDir = $this->directoryMapper->getDirectoryOfUser($id ,$this->userId);
		} catch (\OCP\DB\Exception | DoesNotExistException $e) {
			return new DataResponse('No such directory', 400);
		}

		if ($dbDir->getPath() !== $directoryPath) {
			return new DataResponse('No such directory', 400);
		}
		$userFolder = $this->root->getUserFolder($this->userId);

		try {
			$dbDir = $this->directoryMapper->getDirectoryOfUserByPath($directoryPath, $this->userId);
		} catch (\OCP\DB\Exception | DoesNotExistException $e) {
			return new DataResponse('No such directory', 400);
		}
		if ($directoryPath === null || !$userFolder->nodeExists($directoryPath)) {
			return new DataResponse('No such directory', 400);
		}
		$folder = $userFolder->get($directoryPath);
		if (!$folder instanceof Folder) {
			return new DataResponse('Directory is not a directory', 400);
		}

		$optionValues = $this->processService->getSharedMountedOptionValue($this->userId);
		$sharedAllowed = $optionValues['sharedAllowed'];
		$mountedAllowed = $optionValues['mountedAllowed'];

		// Convert KML to GPX
		// only if we want to display a folder AND it exists AND we want
		// to compute AND we find GPSBABEL AND file was not already converted

		if ($directoryPath === '/') {
			$directoryPath = '';
		}

		$filesByExtension = [];
		foreach($this->extensions as $ext => $gpsbabel_fmt) {
			$filesByExtension[$ext] = [];
		}

		foreach ($folder->getDirectoryListing() as $ff) {
			if ($ff instanceof File) {
				$ffext = '.' . strtolower(pathinfo($ff->getName(), PATHINFO_EXTENSION));
				if (in_array($ffext, array_keys($this->extensions))) {
					// if shared files are allowed or it is not shared
					if ($sharedAllowed || !$ff->isShared()) {
						$filesByExtension[$ffext][] = $ff;
					}
				}
			}
		}

		$this->convertFiles($userFolder, $directoryPath, $this->userId, $filesByExtension);

		// PROCESS gpx files and fill DB
		$this->processService->processGpxFiles($this->userId, $dbDir->getId(), $sharedAllowed, $mountedAllowed, $processAll);

		// build tracks array
		$dbTracks = $this->trackMapper->getDirectoryTracksOfUser($this->userId, $dbDir->getId());

		$that = $this;
		$filteredTracks = array_filter($dbTracks, static function(\OCA\GpxPod\Db\Track $dbTrack) use ($userFolder, $sharedAllowed, $that) {
			if ($userFolder->nodeExists($dbTrack->getTrackpath())) {
				$file = $userFolder->get($dbTrack->getTrackpath());
				return $file instanceof File && ($sharedAllowed || !$file->isShared());
			}
			// CLEANUP DB for non-existing files
			$that->trackMapper->delete($dbTrack);
			return false;
		});

		$jsonTracks = array_map(static function(\OCA\GpxPod\Db\Track $track) {
			$jsonTrack = $track->jsonSerialize();
			$jsonTrack['geojson'] = null;
			$jsonTrack['onTop'] = false;
			$jsonTrack['loading'] = false;
			$jsonTrack['color'] = $jsonTrack['color'] ?? '#0693e3';
			$decodedMarker = json_decode($jsonTrack['marker'], true);
			foreach (Application::MARKER_FIELDS as $k => $v) {
				$jsonTrack[$k] = $decodedMarker[$v];
			}
			unset($jsonTrack['marker']);
			return $jsonTrack;
		}, $filteredTracks);

		$tracksById = [];
		foreach ($jsonTracks as $jsonTrack) {
			$tracksById[$jsonTrack['id']] = $jsonTrack;
		}

		$picturesJsonTxt = $this->processService->getGeoPicsFromFolder($this->userId, $directoryPath);

		return new DataResponse([
			'tracks' => $tracksById,
			'pictures' => $picturesJsonTxt,
		]);
	}

	private function convertFiles($userFolder, $subfolder, $userId, $filesByExtension) {
		// convert kml, tcx etc...
		if (    $userFolder->nodeExists($subfolder)
			&& $userFolder->get($subfolder)->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {

			$gpsbabel_path = $this->toolsService->getProgramPath('gpsbabel');
			$igctrack = $this->config->getUserValue($userId, 'gpxpod', 'igctrack');

			if ($gpsbabel_path !== null) {
				foreach ($this->extensions as $ext => $gpsbabel_fmt) {
					if ($ext !== '.gpx' && $ext !== '.jpg') {
						$igcfilter1 = '';
						$igcfilter2 = '';
						if ($ext === '.igc') {
							if ($igctrack === 'pres') {
								$igcfilter1 = '-x';
								$igcfilter2 = 'track,name=PRESALTTRK';
							} elseif ($igctrack === 'gnss') {
								$igcfilter1 = '-x';
								$igcfilter2 = 'track,name=GNSSALTTRK';
							}
						}
						foreach ($filesByExtension[$ext] as $f) {
							$name = $f->getName();
							$gpx_targetname = str_replace($ext, '.gpx', $name);
							$gpx_targetname = str_replace(strtoupper($ext), '.gpx', $gpx_targetname);
							$gpx_targetfolder = $f->getParent();
							if (! $gpx_targetfolder->nodeExists($gpx_targetname)) {
								// we read content, then launch the command, then write content on stdin
								// then read gpsbabel stdout then write it in a NC file
								$content = $f->getContent();

								if ($igcfilter1 !== '') {
									$args = ['-i', $gpsbabel_fmt, '-f', '-',
										$igcfilter1, $igcfilter2, '-o',
										'gpx', '-F', '-'];
								} else {
									$args = ['-i', $gpsbabel_fmt, '-f', '-',
										'-o', 'gpx', '-F', '-'];
								}
								$cmdparams = '';
								foreach ($args as $arg) {
									$shella = escapeshellarg($arg);
									$cmdparams .= " $shella";
								}
								$descriptorspec = [
									0 => ['pipe', 'r'],
									1 => ['pipe', 'w'],
									2 => ['pipe', 'w']
								];
								$process = proc_open(
									$gpsbabel_path.' '.$cmdparams,
									$descriptorspec,
									$pipes
								);
								// write to stdin
								fwrite($pipes[0], $content);
								fclose($pipes[0]);
								// read from stdout
								$gpx_clear_content = stream_get_contents($pipes[1]);
								fclose($pipes[1]);
								// read from stderr
								$stderr = stream_get_contents($pipes[2]);
								fclose($pipes[2]);

								$return_value = proc_close($process);

								// write result in NC files
								$gpx_file = $gpx_targetfolder->newFile($gpx_targetname);
								$gpx_file->putContent($gpx_clear_content);
							}
						}
					}
				}
			} else {
				// Fallback for igc without GpsBabel
				foreach ($filesByExtension['.igc'] as $f) {
					$name = $f->getName();
					$gpx_targetname = str_replace(['.igc', '.IGC'], '.gpx', $name);
					$gpx_targetfolder = $f->getParent();
					if (! $gpx_targetfolder->nodeExists($gpx_targetname)) {
						$fdesc = $f->fopen('r');
						$gpx_clear_content = $this->conversionService->igcToGpx($fdesc, $igctrack);
						fclose($fdesc);
						$gpx_file = $gpx_targetfolder->newFile($gpx_targetname);
						$gpx_file->putContent($gpx_clear_content);
					}
				}
				// Fallback KML conversion without GpsBabel
				foreach ($filesByExtension['.kml'] as $f) {
					$name = $f->getName();
					$gpx_targetname = str_replace(['.kml', '.KML'], '.gpx', $name);
					$gpx_targetfolder = $f->getParent();
					if (! $gpx_targetfolder->nodeExists($gpx_targetname)) {
						$content = $f->getContent();
						$gpx_clear_content = $this->conversionService->kmlToGpx($content);
						$gpx_file = $gpx_targetfolder->newFile($gpx_targetname);
						$gpx_file->putContent($gpx_clear_content);
					}
				}
				// Fallback TCX conversion without GpsBabel
				foreach ($filesByExtension['.tcx'] as $f) {
					$name = $f->getName();
					$gpx_targetname = str_replace(['.tcx', '.TCX'], '.gpx', $name);
					$gpx_targetfolder = $f->getParent();
					if (! $gpx_targetfolder->nodeExists($gpx_targetname)) {
						$content = $f->getContent();
						$gpx_clear_content = $this->conversionService->tcxToGpx($content);
						$gpx_file = $gpx_targetfolder->newFile($gpx_targetname);
						$gpx_file->putContent($gpx_clear_content);
					}
				}
			}
		}
	}

	/**
	 * delete from DB all entries refering to absent files
	 * optional parameter : folder to clean
	 */
	private function cleanDbFromAbsentFiles(string $userId, ?int $directoryId = null) {
		$userFolder = $this->root->getUserFolder($userId);

		/** @var \OCA\GpxPod\Db\Track[] $dbDirTracks */
		$dbDirTracks = $directoryId === null
			? $this->trackMapper->getTracksOfUser($userId)
			: $this->trackMapper->getDirectoryTracksOfUser($userId, $directoryId);
		foreach ($dbDirTracks as $dbDirTrack) {
			if ($userFolder->nodeExists($dbDirTrack->getTrackpath())) {
				$node = $userFolder->get($dbDirTrack->getTrackpath());
				if (!$node instanceof File) {
					// not a file
					$this->trackMapper->delete($dbDirTrack);
				}
			} else {
				// does not exist
				$this->trackMapper->delete($dbDirTrack);
			}
		}
	}
}
