<?php
namespace App\Radio\Backend;

use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Filesystem;
use Azura\EventDispatcher;
use App\Radio\Adapters;
use App\Radio\AutoDJ;
use Doctrine\ORM\EntityManager;
use App\Entity;
use Monolog\Logger;
use Psr\Http\Message\UriInterface;
use Supervisor\Supervisor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Liquidsoap extends AbstractBackend implements EventSubscriberInterface
{
    /** @var AutoDJ */
    protected $autodj;

    /** @var Filesystem */
    protected $filesystem;

    /**
     * @param EntityManager $em
     * @param Supervisor $supervisor
     * @param Logger $logger
     * @param EventDispatcher $dispatcher
     * @param AutoDJ $autodj
     * @param Filesystem $filesystem
     *
     * @see \App\Provider\RadioProvider
     */
    public function __construct(
        EntityManager $em,
        Supervisor $supervisor,
        Logger $logger,
        EventDispatcher $dispatcher,
        AutoDJ $autodj,
        Filesystem $filesystem
    ) {
        parent::__construct($em, $supervisor, $logger, $dispatcher);

        $this->autodj = $autodj;
        $this->filesystem = $filesystem;
    }

    public static function getSubscribedEvents()
    {
        return [
            AnnotateNextSong::NAME => [
                ['annotateNextSong', 0],
            ],
            WriteLiquidsoapConfiguration::NAME => [
                ['writeHeaderFunctions', 25],
                ['writePlaylistConfiguration', 20],
                ['writeHarborConfiguration', 15],
                ['writeCustomConfiguration', 10],
                ['writeLocalBroadcastConfiguration', 5],
                ['writeRemoteBroadcastConfiguration', 0],
            ],
        ];
    }

    /**
     * Write configuration from Station object to the external service.
     *
     * Special thanks to the team of PonyvilleFM for assisting with Liquidsoap configuration and debugging.
     *
     * @param Entity\Station $station
     * @return bool
     */
    public function write(Entity\Station $station): bool
    {
        $event = new WriteLiquidsoapConfiguration($station);
        $this->dispatcher->dispatch(WriteLiquidsoapConfiguration::NAME, $event);

        $ls_config_contents = $event->buildConfiguration();

        $config_path = $station->getRadioConfigDir();
        $ls_config_path = $config_path . '/liquidsoap.liq';

        file_put_contents($ls_config_path, $ls_config_contents);
        return true;
    }

    public function writeHeaderFunctions(WriteLiquidsoapConfiguration $event)
    {
        $event->prependLines([
            '# WARNING! This file is automatically generated by AzuraCast.',
            '# Do not update it directly!',
        ]);

        $station = $event->getStation();
        $config_path = $station->getRadioConfigDir();

        $event->appendLines([
            'set("init.daemon", false)',
            'set("init.daemon.pidfile.path","' . $config_path . '/liquidsoap.pid")',
            'set("log.file.path","' . $config_path . '/liquidsoap.log")',
            (APP_INSIDE_DOCKER ? 'set("log.stdout", true)' : ''),
            'set("server.telnet",true)',
            'set("server.telnet.bind_addr","'.(APP_INSIDE_DOCKER ? '0.0.0.0' : '127.0.0.1').'")',
            'set("server.telnet.port", ' . $this->_getTelnetPort($station) . ')',
            'set("harbor.bind_addrs",["0.0.0.0"])',
            '',
            'set("tag.encodings",["UTF-8","ISO-8859-1"])',
            'set("encoder.encoder.export",["artist","title","album","song"])',
            '',
            '# AutoDJ Next Song Script',
            'def azuracast_next_song() =',
            '  uri = get_process_lines("'.$this->_getApiUrlCommand($station, 'nextsong').'")',
            '  uri = list.hd(uri, default="")',
            '  log("AzuraCast Raw Response: #{uri}")',
            '  ',
            '  if uri == "" or string.match(pattern="Error", uri) then',
            '    log("AzuraCast Error: Delaying subsequent requests...")',
            '    system("sleep 2")',
            '    request.create("")',
            '  else',
            '    request.create(uri)',
            '  end',
            'end',
            '',
            '# DJ Authentication',
            'def dj_auth(user,password) =',
            '  log("Authenticating DJ: #{user}")',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand($station, 'auth', ['dj_user' => '#{user}', 'dj_password' => '#{password}']).'")',
            '  ret = list.hd(ret, default="")',
            '  log("AzuraCast DJ Auth Response: #{ret}")',
            '  bool_of_string(ret)',
            'end',
            '',
            'live_enabled = ref false',
            '',
            'def live_connected(header) =',
            '  log("DJ Source connected! #{header}")',
            '  live_enabled := true',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand($station, 'djon').'")',
            '  log("AzuraCast Live Connected Response: #{ret}")',
            'end',
            '',
            'def live_disconnected() =',
            '  log("DJ Source disconnected!")',
            '  live_enabled := false',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand($station, 'djoff').'")',
            '  log("AzuraCast Live Disconnected Response: #{ret}")',
            'end',
        ]);
    }

    public function writePlaylistConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $playlist_path = $station->getRadioPlaylistsDir();
        $ls_config = [];

        // Clear out existing playlists directory.
        $current_playlists = array_diff(scandir($playlist_path, SCANDIR_SORT_NONE), ['..', '.']);
        foreach ($current_playlists as $list) {
            @unlink($playlist_path . '/' . $list);
        }

        // Set up playlists using older format as a fallback.
        $ls_config[] = '# Fallback Playlists';

        $has_default_playlist = false;
        $playlist_objects = [];

        foreach ($station->getPlaylists() as $playlist_raw) {
            /** @var Entity\StationPlaylist $playlist_raw */
            if (!$playlist_raw->getIsEnabled()) {
                continue;
            }
            if ($playlist_raw->getType() === Entity\StationPlaylist::TYPE_DEFAULT) {
                $has_default_playlist = true;
            }

            $playlist_objects[] = $playlist_raw;
        }

        // Create a new default playlist if one doesn't exist.
        if (!$has_default_playlist) {

            $this->logger->info('No default playlist existed for this station; new one was automatically created.', ['station_id' => $station->getId(), 'station_name' => $station->getName()]);

            // Auto-create an empty default playlist.
            $default_playlist = new Entity\StationPlaylist($station);
            $default_playlist->setName('default');

            /** @var EntityManager $em */
            $this->em->persist($default_playlist);
            $this->em->flush();

            $playlist_objects[] = $default_playlist;
        }

        $playlist_weights = [];
        $playlist_vars = [];

        $special_playlists = [
            'once_per_x_songs' => [
                '# Once per x Songs Playlists',
            ],
            'once_per_x_minutes' => [
                '# Once per x Minutes Playlists',
            ],
        ];
        $schedule_switches = [];

        foreach ($playlist_objects as $playlist) {

            /** @var Entity\StationPlaylist $playlist */

            $playlist_var_name = 'playlist_' . $playlist->getShortName();

            if ($playlist->getSource() === Entity\StationPlaylist::SOURCE_SONGS) {
                $playlist_file_contents = $playlist->export('m3u', true);
                $playlist_file_path =  $playlist_path . '/' . $playlist_var_name . '.m3u';

                file_put_contents($playlist_file_path, $playlist_file_contents);

                $playlist_mode = $playlist->getOrder() === Entity\StationPlaylist::ORDER_SEQUENTIAL
                    ? 'normal'
                    : 'randomize';

                $playlist_params = [
                    'reload_mode="watch"',
                    'mode="'.$playlist_mode.'"',
                    '"'.$playlist_file_path.'"',
                ];

                $ls_config[] = $playlist_var_name . ' = audio_to_stereo(playlist('.implode(',', $playlist_params).'))';
            } else {
                switch($playlist->getRemoteType())
                {
                    case Entity\StationPlaylist::REMOTE_TYPE_PLAYLIST:
                        $ls_config[] = $playlist_var_name . ' = audio_to_stereo(playlist("'.$this->_cleanUpString($playlist->getRemoteUrl()).'"))';
                        break;

                    case Entity\StationPlaylist::REMOTE_TYPE_STREAM:
                    default:
                        $remote_url = $playlist->getRemoteUrl();
                        $remote_url_scheme = parse_url($remote_url, \PHP_URL_SCHEME);
                        $remote_url_function = ('https' === $remote_url_scheme) ? 'input.https' : 'input.http';

                        $ls_config[] = $playlist_var_name . ' = audio_to_stereo(mksafe('.$remote_url_function.'(max=20., "'.$this->_cleanUpString($remote_url).'")))';
                        break;
                }
            }

            if (Entity\StationPlaylist::TYPE_ADVANCED === $playlist->getType()) {
                $ls_config[] = 'ignore('.$playlist_var_name.')';
            }

            switch($playlist->getType())
            {
                case Entity\StationPlaylist::TYPE_DEFAULT:
                    $playlist_weights[] = $playlist->getWeight();
                    $playlist_vars[] = $playlist_var_name;
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_X_SONGS:
                    $special_playlists['once_per_x_songs'][] = 'radio = rotate(weights=[1,' . $playlist->getPlayPerSongs() . '], [' . $playlist_var_name . ', radio])';
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_X_MINUTES:
                    $delay_seconds = $playlist->getPlayPerMinutes() * 60;
                    $special_playlists['once_per_x_minutes'][] = 'delay_' . $playlist_var_name . ' = delay(' . $delay_seconds . '., ' . $playlist_var_name . ')';
                    $special_playlists['once_per_x_minutes'][] = 'radio = fallback([delay_' . $playlist_var_name . ', radio])';
                    break;

                case Entity\StationPlaylist::TYPE_SCHEDULED:
                    $play_time = $this->_getTime($playlist->getScheduleStartTime()) . '-' . $this->_getTime($playlist->getScheduleEndTime());

                    $playlist_schedule_days = $playlist->getScheduleDays();
                    if (!empty($playlist_schedule_days)) {
                        $play_days = [];

                        foreach($playlist_schedule_days as $day) {
                            $day = (int)$day;
                            $play_days[] = (($day === 7) ? '0' : $day).'w';
                        }

                        $play_time = '('.implode(' or ', $play_days).') and '.$play_time;
                    }

                    $schedule_switches[] = '({ ' . $play_time . ' }, ' . $playlist_var_name . ')';
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_DAY:
                    $play_time = $this->_getTime($playlist->getPlayOnceTime());

                    $playlist_once_days = $playlist->getPlayOnceDays();
                    if (!empty($playlist_once_days)) {
                        $play_days = [];

                        foreach($playlist_once_days as $day) {
                            $day = (int)$day;
                            $play_days[] = (($day === 7) ? '0' : $day).'w';
                        }

                        $play_time = '('.implode(' or ', $play_days).') and '.$play_time;
                    }

                    $schedule_switches[] = '({ ' . $play_time . ' }, ' . $playlist_var_name . ')';
                    break;
            }
        }

        $ls_config[] = '';

        // Build "default" type playlists.
        $ls_config[] = '# Standard Playlists';
        $ls_config[] = 'radio = random(weights=[' . implode(', ', $playlist_weights) . '], [' . implode(', ',
                $playlist_vars) . ']);';
        $ls_config[] = '';

        // Add in special playlists if necessary.
        foreach($special_playlists as $playlist_type => $playlist_config_lines) {
            if (count($playlist_config_lines) > 1) {
                $ls_config = array_merge($ls_config, $playlist_config_lines);
                $ls_config[] = '';
            }
        }

        $schedule_switches[] = '({ true }, radio)';
        $ls_config[] = '# Assemble final playback order';
        $fallbacks = [];

        if ($station->useManualAutoDJ()) {
            $ls_config[] = 'requests = audio_to_stereo(request.queue(id="'.$this->_getVarName('requests', $station).'"))';
            $fallbacks[] = 'requests';
        } else {
            $ls_config[] = 'dynamic = audio_to_stereo(request.dynamic(id="'.$this->_getVarName('next_song', $station).'", timeout=20., azuracast_next_song))';
            $ls_config[] = 'dynamic = cue_cut(id="'.$this->_getVarName('cue_cut', $station).'", dynamic)';
            $fallbacks[] = 'dynamic';
        }

        $fallbacks[] = 'switch([ ' . implode(', ', $schedule_switches) . ' ])';
        $fallbacks[] = 'blank(duration=2.)';

        $ls_config[] = 'radio = fallback(id="'.$this->_getVarName('playlist_fallback', $station).'", track_sensitive = '.($station->useManualAutoDJ() ? 'true' : 'false').', ['.implode(', ', $fallbacks).'])';

        $event->appendLines($ls_config);
    }

    public function writeHarborConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';

        $harbor_params = [
            '"/"',
            'id="'.$this->_getVarName('input_streamer', $station).'"',
            'port='.$this->getStreamPort($station),
            'user="shoutcast"',
            'auth=dj_auth',
            'icy=true',
            'max=30.',
            'buffer='.((int)($settings['dj_buffer'] ?? 5)).'.',
            'icy_metadata_charset="'.$charset.'"',
            'metadata_charset="'.$charset.'"',
            'on_connect=live_connected',
            'on_disconnect=live_disconnected',
        ];

        $event->appendLines([
            '# A Pre-DJ source of radio that can be broadcasted if needed',
            'radio_without_live = radio',
            'ignore(radio_without_live)',
            '',
            '# Live Broadcasting',
            'live = audio_to_stereo(input.harbor('.implode(', ', $harbor_params).'))',
            'ignore(output.dummy(live, fallible=true))',
            'live = fallback(id="'.$this->_getVarName('live_fallback', $station).'", track_sensitive=false, [live, blank(duration=2.)])',
            '',
            'radio = switch(id="'.$this->_getVarName('live_switch', $station).'", track_sensitive=false, [({!live_enabled}, live), ({true}, radio)])',
        ]);
    }

    public function writeCustomConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $settings = (array)$station->getBackendConfig();

        // Crossfading
        $crossfade = round($settings['crossfade'] ?? 2, 1);
        if ($crossfade > 0) {
            $start_next = round($crossfade * 1.5, 2);

            $event->appendLines([
                '# Crossfading',
                'radio = crossfade(start_next=' . self::toFloat($start_next) . ',fade_out=' . self::toFloat($crossfade) . ',fade_in=' . self::toFloat($crossfade) . ',radio)',
            ]);
        }

        $event->appendLines([
            '# Allow for Telnet-driven insertion of custom metadata.',
            'radio = server.insert_metadata(id="custom_metadata", radio)',
        ]);

        $event->appendLines([
            '# Apply amplification metadata (if supplied)',
            'radio = amplify(1., radio)',
        ]);

        // Custom configuration
        if (!empty($settings['custom_config'])) {
            $event->appendLines([
                '# Custom Configuration (Specified in Station Profile)',
                $settings['custom_config'],
            ]);
        }

    }

    public function writeLocalBroadcastConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        $ls_config = [
            '# Local Broadcasts',
        ];

        // Configure the outbound broadcast.
        $i = 0;
        foreach ($station->getMounts() as $mount_row) {
            $i++;

            /** @var Entity\StationMount $mount_row */
            if (!$mount_row->getEnableAutodj()) {
                continue;
            }

            $ls_config[] = $this->_getOutputString($station, $mount_row, 'local_'.$i);
        }

        $event->appendLines($ls_config);
    }

    public function writeRemoteBroadcastConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        $ls_config = [
            '# Remote Relays',
        ];

        // Set up broadcast to remote relays.
        $i = 0;
        foreach($station->getRemotes() as $remote_row) {
            $i++;

            /** @var Entity\StationRemote $remote_row */
            if (!$remote_row->getEnableAutodj()) {
                continue;
            }

            $ls_config[] = $this->_getOutputString($station, $remote_row, 'relay_'.$i);
        }

        $event->appendLines($ls_config);
    }

    /**
     * Returns the URL that LiquidSoap should call when attempting to execute AzuraCast API commands.
     *
     * @param Entity\Station $station
     * @param $endpoint
     * @param array $params
     * @return string
     */
    protected function _getApiUrlCommand(Entity\Station $station, $endpoint, $params = [])
    {
        // Docker cURL-based API URL call with API authentication.
        if (APP_INSIDE_DOCKER) {
            $params = (array)$params;
            $params['api_auth'] = $station->getAdapterApiKey();

            $api_url = 'http://nginx/api/internal/'.$station->getId().'/'.$endpoint;
            $curl_request = 'curl -s --request POST --url '.$api_url;
            foreach($params as $param_key => $param_val) {
                $curl_request .= ' --form '.$param_key.'='.$param_val;
            }

            return $curl_request;
        }

        // Traditional shell-script call.
        $shell_path = '/usr/bin/php '.APP_INCLUDE_ROOT.'/util/cli.php';

        $shell_args = [];
        $shell_args[] = 'azuracast:internal:'.$endpoint;
        $shell_args[] = $station->getId();

        foreach((array)$params as $param_key => $param_val) {
            $shell_args [] = '--'.$param_key.'=\''.$param_val.'\'';
        }

        return $shell_path.' '.implode(' ', $shell_args);
    }

    /**
     * Configure the time offset
     *
     * @param $time_code
     * @return string
     */
    protected function _getTime($time_code)
    {
        $hours = floor($time_code / 100);
        $mins = $time_code % 100;

        $system_time_zone = \App\Utilities::get_system_time_zone();
        $app_time_zone = 'UTC';

        if ($system_time_zone !== $app_time_zone) {
            $system_tz = new \DateTimeZone($system_time_zone);
            $system_dt = new \DateTime('now', $system_tz);
            $system_offset = $system_tz->getOffset($system_dt);

            $app_tz = new \DateTimeZone($app_time_zone);
            $app_dt = new \DateTime('now', $app_tz);
            $app_offset = $app_tz->getOffset($app_dt);

            $offset = $system_offset - $app_offset;
            $offset_hours = floor($offset / 3600);

            $hours += $offset_hours;
        }

        $hours %= 24;
        if ($hours < 0) {
            $hours += 24;
        }

        return $hours . 'h' . $mins . 'm';
    }

    /**
     * Filter a user-supplied string to be a valid LiquidSoap config entry.
     *
     * @param $string
     * @return mixed
     */
    protected function _cleanUpString($string)
    {
        return str_replace(['"', "\n", "\r"], ['\'', '', ''], $string);
    }

    /**
     * Given an original name and a station, return a filtered prefixed variable identifying the station.
     *
     * @param $original_name
     * @param Entity\Station $station
     * @return string
     */
    protected function _getVarName($original_name, Entity\Station $station): string
    {
        $short_name = $this->_cleanUpString($station->getShortName());

        return (!empty($short_name))
            ? $short_name.'_'.$original_name
            : 'station_'.$station->getId().'_'.$original_name;
    }

    /**
     * Given outbound broadcast information, produce a suitable LiquidSoap configuration line for the stream.
     *
     * @param Entity\Station $station
     * @param Entity\StationMountInterface $mount
     * @param string $id
     * @return string
     */
    protected function _getOutputString(Entity\Station $station, Entity\StationMountInterface $mount, $id = '')
    {
        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';

        $bitrate = (int)($mount->getAutodjBitrate() ?? 128);

        switch(strtolower($mount->getAutodjFormat()))
        {
            case $mount::FORMAT_AAC:
                $output_format = '%fdkaac(channels=2, samplerate=44100, bitrate='.(int)$bitrate.', afterburner=true, aot="mpeg4_he_aac_v2", transmux="adts", sbr_mode=true)';
                break;

            case $mount::FORMAT_OGG:
                $output_format = '%vorbis.cbr(samplerate=44100, channels=2, bitrate=' . (int)$bitrate . ')';
                break;

            case $mount::FORMAT_OPUS:
                $output_format = '%opus(samplerate=48000, bitrate='.(int)$bitrate.', vbr="none", application="audio", channels=2, signal="music", complexity=10, max_bandwidth="full_band")';
                break;

            case $mount::FORMAT_MP3:
            default:
                $output_format = '%mp3(samplerate=44100, stereo=true, bitrate=' . (int)$bitrate . ', id3v2=true)';
                break;
        }

        $output_params = [];
        $output_params[] = $output_format;
        $output_params[] = 'id="'.$this->_getVarName($id, $station).'"';

        $output_params[] = 'host = "'.$this->_cleanUpString($mount->getAutodjHost()).'"';
        $output_params[] = 'port = ' . (int)$mount->getAutodjPort();

        $username = $mount->getAutodjUsername();
        if (!empty($username)) {
            $output_params[] = 'user = "'.$this->_cleanUpString($username).'"';
        }

        $output_params[] = 'password = "'.$this->_cleanUpString($mount->getAutodjPassword()).'"';

        if (!empty($mount->getAutodjMount())) {
            $output_params[] = 'mount = "'.$this->_cleanUpString($mount->getAutodjMount()).'"';
        }

        $output_params[] = 'name = "' . $this->_cleanUpString($station->getName()) . '"';
        $output_params[] = 'description = "' . $this->_cleanUpString($station->getDescription()) . '"';
        $output_params[] = 'genre = "'.$this->_cleanUpString($station->getGenre()).'"';

        if (!empty($station->getUrl())) {
            $output_params[] = 'url = "' . $this->_cleanUpString($station->getUrl()) . '"';
        }

        $output_params[] = 'public = '.($mount->getIsPublic() ? 'true' : 'false');
        $output_params[] = 'encoding = "'.$charset.'"';

        if ($mount->getAutodjShoutcastMode()) {
            $output_params[] = 'protocol="icy"';
        }

        $output_params[] = 'radio';

        return 'output.icecast(' . implode(', ', $output_params) . ')';
    }

    /**
     * @inheritdoc
     */
    public function getCommand(Entity\Station $station): ?string
    {
        if ($binary = self::getBinary()) {
            $config_path = $station->getRadioConfigDir() . '/liquidsoap.liq';
            return $binary . ' ' . $config_path;
        }

        return '/bin/false';
    }

    /**
     * If a station uses Manual AutoDJ mode, enqueue a request directly with Liquidsoap.
     *
     * @param Entity\Station $station
     * @param $music_file
     * @return array
     * @throws \Azura\Exception
     */
    public function request(Entity\Station $station, $music_file)
    {
        $requests_var = $this->_getVarName('requests', $station);

        $queue = $this->command($station, $requests_var.'.queue');

        if (!empty($queue[0])) {
            throw new \Exception('Song(s) still pending in request queue.');
        }

        return $this->command($station, $requests_var.'.push ' . $music_file);
    }

    /**
     * Tell LiquidSoap to skip the currently playing song.
     *
     * @param Entity\Station $station
     * @return array
     * @throws \Azura\Exception
     */
    public function skip(Entity\Station $station)
    {
        return $this->command(
            $station,
            $this->_getVarName('local_1', $station).'.skip'
        );
    }

    /**
     * Tell LiquidSoap to disconnect the current live streamer.
     *
     * @param Entity\Station $station
     * @return array
     * @throws \Azura\Exception
     */
    public function disconnectStreamer(Entity\Station $station)
    {
        $current_streamer = $station->getCurrentStreamer();
        $disconnect_timeout = (int)$station->getDisconnectDeactivateStreamer();

        if ($current_streamer instanceof Entity\StationStreamer && $disconnect_timeout > 0) {
            $current_streamer->deactivateFor($disconnect_timeout);

            $this->em->persist($current_streamer);
            $this->em->flush();
        }

        return $this->command(
            $station,
            $this->_getVarName('input_streamer', $station).'.stop'
        );
    }

    /**
     * Execute the specified remote command on LiquidSoap via the telnet API.
     *
     * @param Entity\Station $station
     * @param $command_str
     * @return array
     * @throws \Azura\Exception
     */
    public function command(Entity\Station $station, $command_str)
    {
        $fp = stream_socket_client('tcp://'.(APP_INSIDE_DOCKER ? 'stations' : 'localhost').':' . $this->_getTelnetPort($station), $errno, $errstr, 20);

        if (!$fp) {
            throw new \Azura\Exception('Telnet failure: ' . $errstr . ' (' . $errno . ')');
        }

        fwrite($fp, str_replace(["\\'", '&amp;'], ["'", '&'], urldecode($command_str)) . "\nquit\n");

        $response = [];
        while (!feof($fp)) {
            $response[] = trim(fgets($fp, 1024));
        }

        fclose($fp);

        return $response;
    }

    /**
     * Returns the port used for DJs/Streamers to connect to LiquidSoap for broadcasting.
     *
     * @param Entity\Station $station
     * @return int The port number to use for this station.
     */
    public function getStreamPort(Entity\Station $station): int
    {
        $settings = (array)$station->getBackendConfig();

        if (!empty($settings['dj_port'])) {
            return (int)$settings['dj_port'];
        }

        // Default to frontend port + 5
        $frontend_config = (array)$station->getFrontendConfig();
        $frontend_port = $frontend_config['port'] ?? (8000 + (($station->getId() - 1) * 10));

        return $frontend_port + 5;
    }

    /**
     * Returns the internal port used to relay requests and other changes from AzuraCast to LiquidSoap.
     *
     * @param Entity\Station $station
     * @return int The port number to use for this station.
     */
    protected function _getTelnetPort(Entity\Station $station): int
    {
        $settings = (array)$station->getBackendConfig();
        return (int)($settings['telnet_port'] ?? ($this->getStreamPort($station) - 1));
    }

    /*
     * INTERNAL LIQUIDSOAP COMMANDS
     */

    public function authenticateStreamer(Entity\Station $station, $user, $pass): string
    {
        // Allow connections using the exact broadcast source password.
        $fe_config = (array)$station->getFrontendConfig();
        if (!empty($fe_config['source_pw']) && strcmp($fe_config['source_pw'], $pass) === 0) {
            return 'true';
        }

        // Handle login conditions where the username and password are joined in the password field.
        if (strpos($pass, ',') !== false) {
            [$user, $pass] = explode(',', $pass);
        }
        if (strpos($pass, ':') !== false) {
            [$user, $pass] = explode(':', $pass);
        }

        /** @var Entity\Repository\StationStreamerRepository $streamer_repo */
        $streamer_repo = $this->em->getRepository(Entity\StationStreamer::class);

        $streamer = $streamer_repo->authenticate($station, $user, $pass);

        if ($streamer instanceof Entity\StationStreamer) {
            $this->logger->debug('DJ successfully authenticated.', ['username' => $user]);

            try {
                // Successful authentication: update current streamer on station.
                $station->setCurrentStreamer($streamer);
                $this->em->persist($station);
                $this->em->flush();
            } catch(\Exception $e) {
                $this->logger->error('Error when calling post-DJ-authentication functions.', [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                ]);
            }

            return 'true';
        }

        return 'false';
    }

    /**
     * Pulls the next song from the AutoDJ, dispatches the AnnotateNextSong event and returns the built result.
     *
     * @param Entity\Station $station
     * @param bool $as_autodj
     * @return string
     */
    public function getNextSong(Entity\Station $station, $as_autodj = false): string
    {
        /** @var Entity\SongHistory|string|null $sh */
        $sh = $this->autodj->getNextSong($station, $as_autodj);

        $event = new AnnotateNextSong($station, $sh);
        $this->dispatcher->dispatch(AnnotateNextSong::NAME, $event);

        return $event->buildAnnotations();
    }

    /**
     * Event Handler function for the AnnotateNextSong event.
     *
     * @param AnnotateNextSong $event
     */
    public function annotateNextSong(AnnotateNextSong $event)
    {
        $sh = $event->getNextSong();

        if ($sh instanceof Entity\SongHistory) {
            $media = $sh->getMedia();
            if ($media instanceof Entity\StationMedia) {
                $fs = $this->filesystem->getForStation($event->getStation());
                $media_path = $fs->getFullPath($media->getPathUri());

                $event->setSongPath($media_path);
                $event->addAnnotations($media->getAnnotations());
            } else if (!empty($sh->getAutodjCustomUri())) {
                $custom_uri = $sh->getAutodjCustomUri();

                $event->setSongPath($custom_uri);
                if ($sh->getDuration()) {
                    $event->addAnnotations([
                        'length' => $sh->getDuration(),
                    ]);
                }
            }
        } else if (null !== $sh) {
            $event->setSongPath((string)$sh);
        } else {
            $error_file = APP_INSIDE_DOCKER
                ? '/usr/local/share/icecast/web/error.mp3'
                : APP_INCLUDE_ROOT . '/resources/error.mp3';

            $event->setSongPath($error_file);
        }
    }

    public function toggleLiveStatus(Entity\Station $station, $is_streamer_live = true): void
    {
        $station->setIsStreamerLive($is_streamer_live);

        $this->em->persist($station);
        $this->em->flush();
    }

    public function getWebStreamingUrl(Entity\Station $station, UriInterface $base_url): UriInterface
    {
        $stream_port = $this->getStreamPort($station);

        return $base_url
            ->withScheme('wss')
            ->withPath($base_url->getPath().'/radio/' . $stream_port . '/');
    }

    /**
     * Convert an integer or float into a Liquidsoap configuration compatible float.
     *
     * @param float $number
     * @param int $decimals
     * @return string
     */
    public static function toFloat($number, $decimals = 2): string
    {
        if ((int)$number == $number) {
            return (int)$number.'.';
        }

        return number_format($number, $decimals, '.', '');
    }

    /**
     * @inheritdoc
     */
    public static function getBinary()
    {
        $user_base = \dirname(APP_INCLUDE_ROOT);
        $new_path = $user_base . '/.opam/system/bin/liquidsoap';

        $legacy_path = '/usr/bin/liquidsoap';

        if (APP_INSIDE_DOCKER) {
            // Docker revisions 3 and later use the `radio` container.
            return (APP_DOCKER_REVISION >= 3)
                ? '/usr/local/bin/liquidsoap'
                : $new_path;
        }

        if (file_exists($new_path)) {
            return $new_path;
        }
        if (file_exists($legacy_path)) {
            return $legacy_path;
        }
        return false;
    }
}
