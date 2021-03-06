<?php


class MinecraftLogParser
{

	private $verbose = false;
	private $dbh = false;
	private $debugging = false;

	private $warnings = array();
	private $errors = array();
	
	private $data = array();
	private $mc_server_name = null;
	private $path = null;
	private $results_mode = null;
	
	protected $achievements = null;
	protected $player_achievement_cache = null;

	
	public function __construct($path=null, $result_mode=null, $verbose=null, $mc_server_name=null, $dbh=null, $debugging=null)
	{
		if($path!==null) $this->path = $path;
		if($result_mode!==null) $this->result_mode = $result_mode;
		if($verbose!==null) $this->verbose = $verbose;
		if($mc_server_name!==null) $this->mc_server_name = $mc_server_name;
		if($dbh!==null) $this->setDBH($dbh);
		if($debugging!==null) $this->debugging = $debugging;
		
		$this->fetchAchievements();
		$this->player_achievement_cache = null;
	}// constructor


	public function setDBH($dbh)
	{
		$this->dbh = $dbh;

	}


	protected function fetchAchievements()
	{
		$this->achievements = null;
		$sql = 'SELECT * from achievements';
		try
		{
			$sth = $this->dbh->prepare($sql);
			$sth->execute();
			$this->achievements = $sth->fetchAll();
			//echo "DEBUG Set achievements to:\n";
			//print_r($this->achievements);
		}
		catch (PDOException $e) {
			$this->warnings[] = 'Unabled to fetch achievements: '.$e->getMessage();
		}
	}


	public function parse($path=null, $result_mode=null, $mc_server_name=null) 
	{

		$uuid_mapping = array(); 
		$player_data = array();
		$online = array();
		$online_timeline = array();
		$max_online = array();
		$current_nicknames = array();
		$nicknames = array();
		
		if(empty($path) && !empty($this->path)) $path = $this->path; 
		if(empty($result_mode) && !empty($this->result_mode)) $result_mode = $this->result_mode; 

		$original_cwd = getcwd();

		//echo 'DEBUG path: '.$path."\n";

		if(rtrim($path, DIRECTORY_SEPARATOR)=='.' || empty($path)) $path = getcwd();
		else chdir($path);
		
		if(empty($result_mode)) $result_mode = 'summarized'; 

		if($mc_server_name!==null) $this->mc_server_name = $mc_server_name;
		else if(strpos($path, DIRECTORY_SEPARATOR)!==false)
		{
			// guess the mc_server_name name using the patch
			$path_parts = explode(DIRECTORY_SEPARATOR, $path);
			$part_index = array_search('logs', $path_parts);
			if($part_index>0) $this->mc_server_name = $path_parts[$part_index-1];
		}

		if($this->verbose>1) echo 'NOTICE mc_server_name was determined to be: '.$this->mc_server_name."\n";

		$filenames = glob('*-*-*-*.log*');
		asort($filenames);

		foreach ($filenames as $filename)
		{
			$pattern = '/([\d]+-[\d]+-[\d]+)(?:-([\d]+))?\.([\S]+)$/';
			$found = preg_match($pattern, $filename, $matches);
			if($found)
			{
				$file_date = $matches[1];
				if($this->verbose>-1) echo 'NOTICE Parsing file: '.$filename.' (determined date '.$file_date.")\n";
			}
			else continue;

			$fi = new SplFileInfo($filename);
			if($this->verbose>2) echo 'NOTICE extension is: '.$fi->getExtension(). "\n";
			if(stripos($fi->getExtension(), 'gz')!==false)
			{
				$lines = gzfile($filename);
			}
			else
			{
				$lines = file($filename);
			}

			
			if($this->verbose>1) echo 'NOTICE '.$filename.' has ' .count($lines) . " lines \n";
			
			$next_line_data = null;

			foreach($lines as $li=>$l)
			{
	
				// DEBUG
				//if(strpos($l, ' Rugged')!=false && strpos($l, '/nick')!==false && strpos($l, 'van')!==false )
				//{
					//echo "\n !!!!!!!!!!!!!!!!!!!!!!!!!!!! THIS IS IT THIS IS IT THIS IS IT !!!!!!!!!!!!!!!!!!!!!!! \n\n";
					//echo 'DEBUG Line (line '.($li+1).' of '.$filename.":\n";
					//echo $l;
					//echo "\n";
				//}

				$line_data = $next_line_data;
				$next_line_data = $this->parseLine($l);

				if(is_array($line_data) && count($line_data))
				{
					if(empty($line_data['ign']) && !empty($line_data['nick']))
					{	
						$original_nick = $line_data['nick'];
						$color_tags =         array('[0;30;22m', '[0;34;22m', '[0;32;22m', '[0;36;22m', '[0;31;22m', '[0;35;22m', '[0;33;22m', '[0;37;22m', '[0;30;1m', '[0;34;1m', '[0;32;1m', '[0;36;1m', '[0;31;1m', '[0;35;1m', '[0;33;1m', '[0;37;1m', '[5m', '[21m', '[9m', '[4m', '[3m', '[m');
						$color_replacements = array('&0',        '&1',        '&2',        '&3',        '&4',        '&5',        '&6',        '&7',        '&8',       '&9',       '&a',       '&b',       '&c',       '&d',       '&e',       '&f',       '&k',  '&l',   '&m',  '&n',  '&o',  '&r');
						$line_data['nick'] = str_replace($color_tags, $color_replacements, $line_data['nick']);
						$line_data['nick'] = preg_replace('/[^\x20-\x7E]/', '', $line_data['nick']);
						if(strpos($line_data['nick'], '~')!==false) 
						{
							$nick_parts = explode('~', $line_data['nick']);
							$line_data['nick'] = $nick_parts[1];
						}
						else
						{
							//echo 'DEBUG current nick: '.$line_data['nick']."\n";
							$count = true;
							while($count)
							{
								$line_data['nick'] = preg_replace('/^&[0-9a-z]/', '', $line_data['nick'], -1, $count);
							}
							//echo 'DEBUG original nick: '.$original_nick."\n";
						}
						//$line_data['nick'] = preg_replace('/^~/', '', $line_data['nick']);
						
						$line_data['nick'] = preg_replace('/&r$/', '', $line_data['nick']);
						//if($this->debugging>1 && strpos($line_data['nick'], '[0;')!==false) 
						//{
							//echo "\n !!!!!!!!!!!!!!!!!!!!!!!!!!!! THIS IS IT THIS IS IT THIS IS IT !!!!!!!!!!!!!!!!!!!!!!! \n\n";
							//echo 'DEBUG Trying to find nickname: "'.$line_data['nick']."\" in :\n";
							//print_r($current_nicknames);
							//sleep(2);
						//}
						if(in_array($line_data['nick'], $current_nicknames)) $line_data['uuid'] = array_search($line_data['nick'], $current_nicknames); 
						else 
						{
							$found_match = false;
							// Okay now we are REALLY DESPERATE!
							if(!$found_match)
							{
								foreach($online as $uuid)
								{
									foreach($uuid_mapping as $temp_uuid=>$igns)
									{
										if($temp_uuid==$uuid)
										{
											foreach($igns as $temp_ign)
											{
												if(stripos($temp_ign, $line_data['nick'])!==false)
												{
													//echo "DEBUG 2\n";
													$found_match = true;
													$line_data['uuid'] = $uuid;
													break;		
												}
											}
											if($found_match) break;
										}
									}
									if($found_match) break;
								}
							}
							if(!$found_match)
							{
								foreach($online as $uuid)
								{
 									if(!empty($current_nicknames[$uuid]) && strlen(trim($line_data['nick']))>2 && $this->removeFormatters(strtolower($line_data['nick']))==$this->removeFormatters(strtolower($current_nicknames[$uuid])))
									{
										//echo "DEBUG 3\n";
										$found_match = true;
										$line_data['uuid'] = $uuid;
										break;		
									}
								}
							}
							
							// So so so desperate here
							if(!$found_match)
							{
								foreach($online as $uuid)
								{
									foreach($uuid_mapping as $temp_uuid=>$igns)
									{
										if($temp_uuid==$uuid)
										{
											foreach($igns as $temp_ign)
											{
												if(stripos($line_data['nick'], $temp_ign)!==false)
												{
													//echo "DEBUG 4\n";
													$found_match = true;
													$line_data['uuid'] = $uuid;
													break;		
												}
											}
											if($found_match) break;
										}
									}
									if($found_match) break;
								}
							}
							
		
							
							// DARN Now we have to start going through ALL the nicknames :-(
							if(!$found_match)
							{
								foreach($nicknames as $uuid=>$nick_counts)
								{
									foreach($nick_counts as $nick=>$nick_count)
									{
										if($line_data['nick']==$nick)
										{
											//echo "DEBUG SAD\n";
											$found_match = true;
											$line_data['uuid'] = $uuid;
											break;
										}
									}
									if($found_match) $break;
								}
							}
							



							if($found_match)
							{
								//echo 'DEBUG replacing '.$line_data['uuid'].' nickname: '.$current_nicknames[$line_data['uuid']].' with '.$line_data['nick']."\n";
								//echo $line_data['line']."\n";
								//sleep(2);
								$current_nicknames[$line_data['uuid']] = $line_data['nick'];
								// Also add to the bigger list of nicknames
								if(empty($nicknames[$line_data['uuid']])) $nicknames[$line_data['uuid']] = array();
								if(array_key_exists($line_data['nick'], $nicknames[$line_data['uuid']])) $nicknames[$line_data['uuid']][$line_data['nick']] += 1;
								else $nicknames[$line_data['uuid']][$line_data['nick']] = 1;
							}
							else if($this->debugging>1) 
							{
								echo "DEBUG line:\n";
								print_r($line_data['line']);
								echo "\n";
								echo 'DEBUG DID NOT FIND nickname: "'.$line_data['nick']."\" among current_nicknames\n";
								echo "DEBUG Current Nicknames:\n";
								print_r($current_nicknames);
								//if($line_data['nick']=='NotCinder')
								sleep(5);
								
							}
						}
						
					}

					if(empty($line_data['ign'])) 
					{
						if(!empty($line_data['uuid']) && in_array($line_data['uuid'], array_keys($uuid_mapping))) $line_data['ign'] = $uuid_mapping[$line_data['uuid']][0];
						else 
						{
							if(empty($line_data['uuid'])) $this->warnings[] = 'Uuid is missing in line data';
							else $this->warnings[] = 'Unable to determine ign for uuid: '.$line_data['uuid'];
							//print_r($uuid_mapping);
						}
						//echo 'DEBUG uuid: '.$line_data['uuid'].' has ign: '.$line_data['ign']."\n";
					}

					//if(stripos($line_data['line'], 'Wideline')!==false) 
					//{
						//echo 'DEBUG ign: '.$line_data['ign'].' line: '.$line_data['line']."\n";
						//sleep(1);
					//}
					if(!empty($line_data['ign']))
					{
						if(count($next_line_data) && $next_line_data['line_type']=='command_denial')
						{
							if($line_data['line_type']!='command' || $next_line_data['ign']!=$line_data['ign']) $this->warnings[] = 'A command was denied for '.$next_line_data['ign'].' but previous line was: "'.$line_data['line'].'"';
							else
							{
								//if(stripos($line_data['base_command'],'/nick')===0) echo "!!!!!!!!!!!!!!!!!!!  DEBUG THIS IS IT! DEBUG\n";
								if($this->verbose>2) echo 'NOTICE The command '.$line_data['line'].' was denied for '.$line_data['ign']."\n";
								if($this->verbose>2) echo 'NOTICE '.$next_line_data['line']."\n";
								$line_data['denied'] = true;
							}
						}
						if($line_data['line_type']=='uuid')
						{
							// We want the latest first
							if(empty($current_nicknames[$line_data['uuid']])) $current_nicknames[$line_data['uuid']] = $line_data['ign'];
							if(empty($uuid_mapping[$line_data['uuid']])) $uuid_mapping[$line_data['uuid']] = array();
							// ALWAYS put latest first, even if the ign was already in the listA
							// If the ign is ALREADY the first one, then nothing is needed to be done
							if(count($uuid_mapping[$line_data['uuid']])<1 || $line_data['ign']!=$uuid_mapping[$line_data['uuid']][0])
							{
								// If the ign is ALREADY in the uuid mapping, we need to delete the pre-existing element with the ign
								if(in_array($line_data['ign'], $uuid_mapping[$line_data['uuid']])) 
								{
									//echo 'DEBUG This is it! That special case where a player ('.$current_nicknames[$line_data['uuid']].') has gone back to an old IGN ('.$line_data['ign'].")\n";
									$index_to_delete = array_search($line_data['ign'], $uuid_mapping[$line_data['uuid']]);
									//echo 'DEBUG Index to delete (so it can be re-added as first) is: '.$index_to_delete.' which has value: '.$uuid_mapping[$line_data['uuid']][$index_to_delete]."\n";
									unset($uuid_mapping[$line_data['uuid']][$index_to_delete]);
									// Now reindex the mapping, since mapping is a regular numerical type array
									$uuid_mapping[$line_data['uuid']] = array_values($uuid_mapping[$line_data['uuid']]);
									
								}
								array_unshift($uuid_mapping[$line_data['uuid']], $line_data['ign']); 
							}
						}

						// uuid is critical
						if(empty($line_data['uuid']))
						{
							foreach($uuid_mapping as $uuid=>$igns)
							{
								foreach($igns as $ign)
								{
									if($ign==$line_data['ign']) 
									{
										$line_data['uuid'] = $uuid;
										break;
									}
								}			
								if(!empty($line_data['uuid'])) break;
							}
						}

						if(empty($line_data['uuid']))
						{
							$this->warnings[] = 'Do not have a uuid for line but ign is '.$line_data['ign'];
							//echo 'DEBUG line was: '.$line_data['line']."\n";
						}
						else
						{
							$line_data['date'] = $file_date;
							if(!empty($line_data['time'])) 
							{
								$line_data['timestamp'] = strtotime($file_date.' '.$line_data['time']);
								if($this->verbose>3) echo 'NOTICE Time for line determined to be: '.strtotime($file_date.' '.$line_data['time']).' using "'.$file_date.' '.$line_data['time']."\"\n";
								if($line_data['line_type']=='login' || $line_data['line_type']=='logout')
								{
									if ($line_data['line_type']=='login' && !in_array($line_data['uuid'], $online))
									{
										$online[] = $line_data['uuid'];
										if(empty($max_online['players']) || count($online) > count($max_online['players'])) $max_online = array('timestamp'=>$line_data['timestamp'], 'players'=>$online);
										if($this->debugging>2) echo 'DEBUG added '.$line_data['uuid'].' to online at '.$line_data['timestamp']."\n";
										$online_timeline[$line_data['timestamp']] = count($online);
									}
									if ($line_data['line_type']=='logout' && in_array($line_data['uuid'], $online))
									{
										if($this->debugging>2) echo 'DEBUG removed '.$line_data['uuid'].' from online at '.$line_data['timestamp']."\n";
										unset($online[array_search($line_data['uuid'], $online)]);
										$online_timeline[$line_data['timestamp']] = count($online);
									}
									$time_events[$line_data['uuid']][$line_data['timestamp']][] = $line_data;
								}
							}
							if($line_data['line_type']=='command')
							{	
								// Note nick command is expected to work like Essentials nick
								if (stripos($line_data['base_command'],'/nick')===0 && !empty($line_data['command']) && empty($line_data['denied']))
								{
									//echo 'DEBUG line '.$line_data['line']."\n";

									$command = preg_replace('/[\s]+/', ' ', $line_data['command']);
									$command_parts = explode(' ', trim($command));
									if(!empty($command_parts[2]) && strtolower($command_parts[2])=='on') unset($command_parts[2]);

									$line_data['nick_target'] = $line_data['ign'];
									$nick_target_uuid = false;
									
									if(count($command_parts)>1)
									{
										// It is very simple, if the first argument is 
										$nick = $command_parts[1];
										$target_match = false;
										if(count($command_parts)>2)
										{
											foreach($uuid_mapping as $uuid=>$igns) 
											{
												foreach($igns as $ign)	
												{
													if
													(
														strtolower($command_parts[1])==strtolower($ign)
														||
														(!empty($current_nicknames[$uuid]) && strtolower($command_parts[1])==strtolower($current_nicknames[$uuid]))
														||
														(strlen($command_parts[1])>2 && in_array($ign, $online) && stripos($ign, $command_parts[1])!==false)
													)
													{
														$target_match = true;
														$line_data['nick_target'] = $ign;
														$nick_target_uuid = $uuid;
														$nick = $command_parts[2];
														break;
													}
												}	
												if($target_match) break;
											}
										}
								
										if(strtolower($nick)=='off' || strpos($nick, 'off>')!==false)
										{
											$nick = $line_data['ign'];
											$line_data['nick_target'] = $line_data['ign'];
										}

										if(empty($nick_target_uuid) && !empty($line_data['nick_target']))
										{
											$target_match = false;
											foreach($uuid_mapping as $uuid=>$igns) 
											{
												foreach($igns as $ign)	
												{
													if(strtolower($line_data['nick_target'])==strtolower($ign))
													{
														$target_match = true;
														$nick_target_uuid = $uuid;
														break;
													}
												}	
												if($target_match) break;
											}
											
										}  
										//echo 'DEBUG nick_target_uuid: '.$nick_target_uuid."\n";
										//echo 'DEBUG nick: '.$nick."\n";

										// TODO: A nick cannot be someone else's IGN
										
										$current_nicknames[$nick_target_uuid] = $nick;
										//echo "DEBUG current_nicknames:\n";
										//print_r($current_nicknames);
										if(!array_key_exists($nick_target_uuid, $nicknames)) $nicknames[$nick_target_uuid] = array();
										if(array_key_exists($nick, $nicknames[$nick_target_uuid])) $nicknames[$nick_target_uuid][$nick] += 1;
										else $nicknames[$nick_target_uuid][$nick] = 1;
		
									}
								}
								else if (($line_data['base_command']=='/msg' || $line_data['base_command']=='/tell' || $line_data['base_command']=='/m' || $line_data['base_command']=='/t' || $line_data['base_command']=='/whisper') && !empty($line_data['command']) && empty($line_data['denied']))
								{
									$pattern = '@^/[\S]+[\s]+([\S]+)[\s]+(.+)$@';
									$found = preg_match($pattern, $line_data['command'], $matches);
									if($found)
									{
										if(substr($matches[2], strlen($matches[2])-2)=='[m') $matches[2] = substr($matches[2], 0, strlen($matches[2])-2);
										$player_data[$line_data['uuid']]['chat_messages'][$line_data['timestamp']] = array('message'=>utf8_encode($matches[2]), 'to'=>$matches[1], 'type'=>'msg');
										//echo 'DEBUG Found msg: '.$matches[2].' to: '.$matches[1]."\n";
										$chat_target_uuid = false;
										foreach($online as $temp_uuid)
										{
											$temp_igns = $uuid_mapping[$temp_uuid];
											if(in_array($matches[1], $temp_igns)) 
											{
												$chat_target_uuid = $temp_uuid;
												break;
											}
										}
										if($chat_target_uuid)
										{
											$last_chat_target[$line_data['uuid']] = $matches[1];
											$last_chat_target[$chat_target_uuid] = $line_data['ign'];
										}
									}
								}
								else if (($line_data['base_command']=='/r' || $line_data['base_command']=='/reply') && !empty($line_data['command']) && empty($line_data['denied']))
								{
									$pattern = '@^/[\S]+[\s]+(.+)$@';
									$found = preg_match($pattern, $line_data['command'], $matches);
									if($found)
									{	
										if(substr($matches[1], strlen($matches[1])-2)=='[m') $matches[1] = substr($matches[1], 0, strlen($matches[1])-2);
										$player_data[$line_data['uuid']]['chat_messages'][$line_data['timestamp']] = array('message'=>$matches[1], 'to'=>(empty($last_chat_target[$line_data['uuid']]) ? 'UNKNOWN' : $last_chat_target[$line_data['uuid']].' (UNCERTAIN)'), 'type'=>'r');
										//echo 'DEBUG Found r: '.$matches[1]."\n";
									}
								}
							}
							else if ($line_data['line_type']=='chat')
							{
								if($this->debugging>5) echo 'DEBUG adding line to '.$line_data['uuid'].'\'s chat: '.$line_data['chat_message']."\n";
								if(substr($line_data['chat_message'], strlen($line_data['chat_message'])-2)=='[m') $line_data['chat_message'] = substr($line_data['chat_message'], 0, strlen($line_data['chat_message'])-2);
								$player_data[$line_data['uuid']]['chat_messages'][$line_data['timestamp']] = array('message'=>$line_data['chat_message'], 'to'=>'EVERYONE','type'=>'chat');
							}
							else if ($line_data['line_type']=='death')
							{
								$player_data[$line_data['uuid']]['deaths'][$line_data['death_method']][$line_data['timestamp']] = (!empty($line_data['death_extra']) ? $line_data['death_extra'] : '');
							}
							else if (strpos($line_data['line_type'], 'achievement_')===0)
							{
								$achievement_alias = str_replace('achievement_', '', $line_data['line_type']);
								// Look for a pre-exising achievement of this type in the database

								if(empty($mc_server_id))
								{
									if($this->mc_server_name) $mc_server_id = self::getMCServerIDWithName($this->mc_server_name, $this->dbh);
									if(empty($mc_server_id) && $this->mc_server_id) $mc_server_id = $this->mc_server_id;
								}
								// Save achievement data in cache and also to the database
								$this->insertOrUpdatePlayerAchievement($line_data['uuid'], $achievement_alias, $line_data['timestamp'], $mc_server_id, array('line'=>$line_data['line'], 'confirmed_by'=>'log'));
	
							}
							
							if(in_array($line_data['uuid'], $online)) $player_data[$line_data['uuid']][(empty($line_data['denied']) ? '' : 'denied_').$line_data['line_type']][] = $line_data;
						}// has uuid
					}
				}
			}
			//echo $filename.' has ' .filesize($filename) . " bytes\n";

		}


		foreach($nicknames as $uuid=>&$nicks)
		{
			arsort($nicks);
			//echo 'DEBUG nicknames for '.$uuid.": \n";
			//print_r($nicks);
			$player_data[$uuid]['nicknames'] = $nicks;
		}

		foreach($time_events as $uuid=>&$te)
		{
			$time_data[$uuid] = array();
			ksort($te);
			$time_data = $this->getTimeData($te);
			$time_achievements = $time_data['achievements'];
			unset($time_data['achievements']);
			if(is_array($time_achievements) && count($time_achievements)) 
			{
				foreach($time_achievements as $taa=>$tat) $this->insertOrUpdatePlayerAchievement($uuid, $taa, $tat, $mc_server_id, array('line'=>'determined by time data parsed from logs', 'confirmed_by'=>'log'));
			}
			foreach($time_data as $tdk=>$tdv) $player_data[$uuid][$tdk] = $tdv;
			//echo 'DEBUG time_data for '.$uuid.": \n";
			//print_r($time_data);
		}

		foreach($uuid_mapping as $uuid=>$igns)
		{
			$player_data[$uuid]['igns'] = $igns;
			$player_data[$uuid]['current_nickname'] = $current_nicknames[$uuid];
		}

		foreach($player_data as $uuid=>&$pd)
		{
			if(!empty($pd['chat_messages'])) ksort($pd['chat_messages']);
			//else if($this->verbose>3 || $this->debugging>1) echo 'DEBUG Apparently there are no chat messages for '.$uuid."\n";
		}

		//echo "DEBUG datai for player:\n";
		//print_r($player_data['RuggedSurvivor']['chat_messages']);
		
		//echo "DEBUG online_timeline:\n";
		//print_r($online_timeline);
		
		//echo "DEBUG max_online:\n";
		//print_r($max_online);

		chdir($original_cwd);

		if(!empty($results_mode)) $results_mode = strtolower($results_mode);

		$this->data = array('server_data'=>array('max_online'=>$max_online, 'online_timeline'=>$online_timeline, 'uuids'=>$uuid_mapping));
		if($this->results_mode=='full') $this->data['player_data'] = $player_data;
		else $this->data['summarized_player_data'] = $this->getSummaryForPlayerData($player_data);
	
		return $this->data;

	}


        protected function fetchPlayerAchievement($uuid, $achievement_alias, $mc_server_id=null)
        {
                //TODO Deal with [er=server achievements
                $sth = $this->dbh->prepare('SELECT pa.id AS player_achievement_id, pa.*, a.* FROM player_achievements pa INNER JOIN achievements a ON a.id = pa.achievement_id WHERE pa.player_uuid = :player_uuid AND a.alias = :achievement_alias');
                $sth->execute(array(':player_uuid'=>$uuid, ':achievement_alias' => $achievement_alias));
                return $sth->fetch();
        }


	protected function insertOrUpdatePlayerAchievement($uuid, $achievement_alias, $when_achieved_timestamp, $mc_server_id, $extra_data=null, $dbh_in=null)
	{
	
		if($this->verbose>2) echo 'Notice uuid: '.$uuid.' has earned "'.$achievement_alias.'" according to '.$extra_data['confirmed_by']."\n";

	
		if(empty($mc_server_id))
		{
			if($this->mc_server_name) $mc_server_id = self::getMCServerIDWithName($this->mc_server_name, $this->dbh);
			if(empty($mc_server_id) && $this->mc_server_id) $mc_server_id = $this->mc_server_id;
		}
		
		$achievement_id = null;
		foreach($this->achievements as $a)
		{
			if($a['alias']==$achievement_alias)
			{
				$achievement_id = $a['id'];
				break;
			}
		}

		if(empty($achievement_id)) 
		{
			echo "ERROR We need the achievement ID\n";
			return;
		}

		$pre_existing_achievement = false;		
		if(empty($this->player_achievement_cache[$uuid][$achievement_alias]))
		{
			//echo 'DEBUG found a '.$achievement_alias.' achievement for '.u$uid.' with achievement_id: '.$achievement_id."\n";
			$pre_existing_achievement = $this->fetchPlayerAchievement($uuid, $achievement_alias, $mc_server_id);;
			if($pre_existing_achievement) $this->player_achievement_cache[$uuid][$achievement_alias] = $pre_existing_achievement;
		}
		$sql = false;
		$sth_replacements = null;
		if(isset($this->player_achievement_cache[$uuid][$achievement_alias]) && !empty($this->player_achievement_cache[$uuid][$achievement_alias]['when_achieved']))
		{
			if($when_achieved_timestamp<strtotime($this->player_achievement_cache[$uuid][$achievement_alias]['when_achieved']))
			{
				$sql = 'UPDATE player_achievements SET when_achieved = :when_achieved, mc_server_id = :mc_server_id WHERE id = :player_achievement_id';
				$sth_replacements = array(':when_achieved'=>date('Y-m-d H:i:s', $when_achieved_timestamp), ':mc_server_id'=>$mc_server_id, ':player_achievement_id'=>$this->player_achievement_cache[$uuid][$achievement_alias]['player_achievement_id']);
			}
		}
		else
		{
			// Insert new
			$sql = "INSERT INTO player_achievements (player_uuid, achievement_id, when_achieved, mc_server_id, log_line, confirmed_by) VALUES (:uuid, :achievement_id, :when_achieved, :mc_server_id, :line, 'log')";
			$sth_replacements = array(':uuid'=>$uuid, ':achievement_id'=>$achievement_id, ':when_achieved'=>date('Y-m-d H:i:s', $when_achieved_timestamp), ':mc_server_id'=>$mc_server_id, ':line'=>$extra_data['line']);
		}	
		if($sql)
		{
			$sth = $this->dbh->prepare($sql);
			$result = $sth->execute($sth_replacements);
			if($result)
			{
				$this->player_achievement_cache[$uuid][$achievement_alias]['when_achieved'] = date('Y-m-d H:i:s', $when_achieved_timestamp);
			}
		}		

	}



	private function parseLine($line)
	{
		$data = array();
		//echo 'DEBUGGING line: '.$line;

		//[00:23:01] [Server thread/INFO]: Sugarpop20[/67.188.18.150:55722] logged in with entity id 123904 at ([hub]215.23264611314224, 58.0, 2066.9385436210773)

		// IMPORTANT - Currently assumes that a line can only match one pattern
		// List the important patterns first

		$patterns = array();

		// Parse achievements FIRST
		if($this->achievements)
		{
			foreach($this->achievements as $a)
			{
				if($a['check_method']=='log')
				{
					$patterns['achievement_'.$a['alias']] = array
					(
						'pattern' => $a['log_pattern'],
						'mapping' => explode(', ', $a['pattern_mapping']), 
						'additional_data' => array('achievement_id'=>$a['id']),
					);
				}
			}
		}

		$patterns = array_merge($patterns, array
		(
			'uuid' => array
			(
				'pattern' => '/UUID of player ([\S]+) is ([0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12})/',
				'mapping' => array('line','ign','uuid'),
			),
			'login' => array
			(
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[([^]]+)\]:[\s]+([^[]+)\[/?([\d]+\.[\d]+\.[\d]+\.[\d]+):?([\d]+)?\][\s]+logged in with entity id[\s]+([\d]+)[\s]+at[\s]+\(\[([^]]*)\]([\S]+),[\s]*([\S]+),[\s]*([\S]+)\)@',
				'mapping' => array('line','time','message_type','ign','ip','port','entity_id','world','x','y','z'),
			),
			'logout' => array
			(
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[([^]]+)\]:[\s]+([A-Za-z0-9\_]+)[\s]+lost connection:[\s]+(.*)@',
				'mapping' => array('line','time','message_type','ign','logout_how'),
			),
			'command' => array
			(
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[(Server thread/INFO)\]:[\s]+([\S]+) issued server command:[\s]+((/?[\S]+).*)@',
				'mapping' => array('line','time','message_type','ign','command','base_command'),
			),
			'command_denial' => array
			(
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[([^]]+)\]:[\s]+[^c]+c([\S]+)[\s]+.*4was denied access to command\.$@',
				'mapping' => array('line','time','message_type','ign'),
			),
			'death' => array
			(
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[(Server thread/INFO)\]:[\s]+(?:§[0-9a-z]{1}([\S]+)§[0-9a-z]{1}|([\S]+))[\s]+(?:(died)|(drowned)|tried to swim in (lava)|(went up in flames)|was (slain|shot|burnt|blown up|pummeled) (?:by|to a crisp))(?:[\s]+(.*))?@',
				'mapping' => array('line','time','message_type','ign','ign','death_method','death_method','death_method','death_method','death_method','death_extra'),
			),
			'kick' => array
			(
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[([^]]+)\]:[\s]+(?:§[0-9a-z]Player§[0-9a-z][\s]+)?(?:§[0-9a-z])?(?:~)?([^ ^§]+)(?:§[0-9a-z])?[\s]+(?:§[0-9a-z])?kicked[\s]+([\S]+)[\s]+for[\s]+(.*).$@',
				'mapping' => array('line','time','message_type','kicking','ign','kick_reason'),
			),
			'chat' => array
			(
				//'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[([^]]+)\]:[\s]+(?:<([^>]+)>|(?:(?:[^~]+~|.+;[\d]+m)(.+)\^\[\[m>))[\s]+(.+)\^\[\[m$@',
				'pattern' => '@^\[([\d]+:[\d]+:[\d]+)\][\s]+\[([^]]+)\]:[\s]+[^<]*<([\S]+)>[\s]+(.+)$@',
				'mapping' => array('line','time','message_type','nick','chat_message'),
			),

		));

		$data = array();
		foreach($patterns as $p_type=>$p)
		{
			if(!empty($p['pattern']))
			{
				$found = preg_match($p['pattern'], $line, $matches);
				if($found)
				{
					foreach($matches as $mi=>$m)
					{
						if(!empty($m)) $data[$p['mapping'][$mi]] = $m; 
					}
					$data['line_type'] = $p_type;
					//if($p_type=='command' && $data['base_command']=='/nick')
					//{
						//echo 'DEBUG Found a '.$p_type.": \n"; 
						//print_r($data);
					//}
					if(!empty($p['additional_data']) && count($p['additional_data']))
					{
						foreach($p['additional_data'] as $ad_key=>$ad_value)
						{
							$data[$ad_key] = $ad_value;
						}
					}
					break;
				}
			}
		}
		return $data;
	}


	private function getTimeData($te)
	{
		$milestone_hours = array(500, 1000, 2000, 5000);
		$data = array();
		$data['first_login'] = false;
		$data['last_logout'] = false;
		$data['seconds_played'] = 0;
		$data['seconds_played_in_last_90_days'] = 0;
		$data['days_active'] = 0;
		$data['achievements'] = null;
		$on_server = false;

		// IMPORTANT $te is expected to be sorted on key BEFORE here
		ksort($te);
		$ninety_days_ago = strtotime('-90 DAY');
		//echo 'DEBUG 90 days ago: '.date('Y-m-d', $ninety_days_ago)."\n";

		foreach($te as $timestamp=>$e_set)
		{
			foreach($e_set as $e)
			{
				if(!empty($timestamp))
				{
					if(!is_array($data['days_active'])) $data['days_active'] = array();
					if(!in_array(date('Y-m-d', $timestamp), $data['days_active'])) $data['days_active'][] = date('Y-m-d', $timestamp);
				}
				if ($e['line_type']=='login')
				{
					if($on_server && $on_server!=$timestamp) $this->warnings[] = 'No logout found between '.$on_server.' and '.$timestamp."\n";
					$on_server = $timestamp;

					if ($data['first_login']===false || $timestamp<$data['first_login']) $data['first_login'] = $timestamp;
					
				}
				else if ($e['line_type']=='logout')
				{
					if(!$on_server) $this->warnings[] = 'No login found for logout at '.$timestamp."\n";
					else 
					{
						if ($on_server > $ninety_days_ago) $data['seconds_played_in_last_90_days'] += ($timestamp - $on_server);
						$data['seconds_played'] += ($timestamp - $on_server);
						//echo 'DEBUG Adding '.($timestamp - $on_server).' to seconds_played ('.$data['seconds_played'].")\n";
					}
					foreach($milestone_hours as $mh) 
					{
						if(empty($data['achievements'][$mh.'_hours']) && ($data['seconds_played']/(60*60))>(int)$mh) 
						{
							$data['achievements'][$mh.'_hours'] = $timestamp - (($data['seconds_played']/(60*60))-$mh);
							echo 'DEBUG this player hit '.$mh.' hours at '.date('Y-m-d H:i:s', $timestamp)."\n";
						}
					}
					$on_server = false;
					if ($data['last_logout']===false || $timestamp>$data['last_logout']) $data['last_logout'] = $timestamp;
				}
			}
		}

		return $data;	
	}


	private function getSummaryForPlayerData($player_data)
	{
		$summary = array();
	
		$pull_straight = array('igns', 'first_login', 'last_logout', 'seconds_played', 'seconds_played_in_last_90_days', 'days_active', 'chat_messages', 'current_nickname', 'nicknames', 'deaths', 'kick');

		// For each player we want:
		//	1. "Time Data"
		//      2. nicknames
		//      3. chat messages
		//	4. favorite commands
		//	5. most denied commands

		foreach($player_data as $uuid=>$pd)
		{
			foreach($pull_straight as $ps)
			{
				if(!empty($pd[$ps])) $summary[$uuid][$ps] = $pd[$ps];
				else $this->warnings[] = 'Unable to pull '.$ps.' from original data for '.$uuid.' ('.$pd['igns'][0].')';
			}
			if(!empty($pd['command'])) $summary[$uuid]['favorite_commands'] = $this->orderByFrequency($pd['command'], 'base_command', 10); 
			if(!empty($pd['denied_command'])) $summary[$uuid]['most_denied_commands'] = $this->orderByFrequency($pd['denied_command'], 'base_command', 10);		
		}

		return $summary;		
	}


	private function orderByFrequency($data, $what, $limit=false)
	{
		$results = array();
		foreach($data as $d)
		{
			if(empty($d[$what])) $this->warnings[] = 'DEBUG what: '.$what." is empty or missing\n";
			else 
			{
				if(empty($results[$d[$what]])) $results[$d[$what]] = 1; 
				else $results[$d[$what]]++;
			}
		}
		arsort($results);
		if($limit && is_numeric($limit)) $results = array_slice($results, 0, $limit);
		return $results;
	}


	private function removeFormatters($text)
	{
		//echo 'DEBUG Came in with: '.$text."\n";
		$count = true;
		while($count)
		{
			$text = preg_replace('/&[0-9,a-z]/', '', $text, -1, $count);
		}
		$text = str_replace('~', '', $text);
		//echo 'DEBUG Leaving with: '.$text."\n";
		return $text;
	}


	public function getData()
	{
		return $this->data;
	}

	public static function getMCServerIDWithName($mc_server_name, $dbh)
	{
			$sth = $dbh->prepare('SELECT id FROM mc_servers WHERE name = :mc_server_name');	
			$sth->execute(array(':mc_server_name'=>$mc_server_name));
			return $sth->fetchColumn();
	}


	public function saveDataToDatabase($dbh=null, $mc_server_name=null)
	{
		if($dbh && is_object($dbh)) $this->setDBH($dbh);

		if(!is_object($this->dbh)) 
		{
			throw new Exception('You must set dbh as a PDO instance');
			return false;
		}

		if(empty($mc_server_name))
		{
			if(!empty($this->mc_server_name)) $mc_server_name = $this->mc_server_name;
			else
			{
				throw new Exception('You must specify a Minecraft server name');
				return false;
			}
		}

		if($mc_server_name) $mc_server_id = self::getMCServerIDWithName($mc_server_name, $this->dbh);

		if(!$mc_server_id && $this->mc_server_id) $mc_server_id = $this->mc_server_id;

		if(!$mc_server_id)
		{
			throw new Exception('There is no Minecraft server named "'.$mc_server_name.'"');
			return false;
		}

		if(!is_array($this->data) && count($this->data)<1)
		{
			throw new Exception('You must first parse the log to get the data');
			return false;
		}
		else
		{
			if($this->results_mode=='full') $player_data_key = 'player_data';
			else $player_data_key = 'summarized_player_data';	
			foreach($this->data[$player_data_key] as $uuid=>$pd)
			{
				//echo 'DEBUG pd for '.$uuid."\n";
				//print_r($pd);
				if(!empty($pd['igns']))
				{
					$sth = $this->dbh->prepare('SELECT name FROM players WHERE uuid = :uuid');	
					$sth->execute(array(':uuid' => $uuid));
					$ign = $sth->fetchColumn();
					if($ign===false)
					{
						// No match. Do an insert
						$stmt = $this->dbh->prepare('INSERT INTO players (uuid, name, old_names) VALUES (:uuid, :name, :old_names)');
						if(!$stmt->execute(array(':uuid' => $uuid, ':name'=>$pd['igns'][0], ':old_names'=>(count($pd['igns'])>1 ? implode(', ',array_splice($pd['igns'], 1)) : null))))
						$this->warnings[] = 'Trouble inserting player with uuid: '.$uuid;
					}
					else if($ign!==$pd['igns'][0])
					{
						//echo 'DEBUG Here igns for ign of '.$ign.' is: '.print_r($pd['igns'], true)."\n";
						// UUID match, but not an IGN match
						// Player likely updated their IGN
						//$this->warnings[] = 'uuid: '.$uuid.' has player entry, but the ign is not: "'.$pd['igns'][0].'" (it is '.$ign.')';
						$stmt = $this->dbh->prepare('UPDATE players set name=:name, old_names=:old_names, names_last_checked=NULL WHERE uuid = :uuid');
						if(!$stmt->execute(array(':uuid' => $uuid, ':name'=>$pd['igns'][0], ':old_names'=>(count($pd['igns'])>1 ? implode(', ',array_splice($pd['igns'], 1)) : null))))
						$this->warnings[] = 'Trouble updating player with uuid: '.$uuid;
					}
				}

				$sth = $this->dbh->prepare('SELECT count(*) FROM player_data WHERE player_uuid = :uuid');	
				$sth->execute(array(':uuid' => $uuid));
				$match_count = $sth->fetchColumn();
				if($match_count)
				{
					// TODO Something more intelligent
					// but for now, just delete the pre-existing player_data row FOR THE PARTICULAR mc_server_id
					$stmt = $this->dbh->prepare('DELETE FROM player_data WHERE player_uuid = :player_uuid AND mc_server_id = :mc_server_id');
					if(!$stmt->execute(array(':player_uuid'=>$uuid, 'mc_server_id'=>$mc_server_id )))
						$this->warnings[] = 'Trouble deleting player_data for uuid: '.$uuid;
				}

				if(empty($pd['days_active'])) $pd['days_active'] = null;			
				if(empty($pd['chat_messages'])) $pd['chat_messages'] = null;			
				if(empty($pd['nicknames'])) $pd['nicknames'] = null;			
				if(empty($pd['favorite_commands'])) $pd['favorite_commands'] = null;			
				if(empty($pd['most_denied_commands'])) $pd['most_denied_commands'] = null;			
				if(empty($pd['deaths'])) $pd['deaths'] = null;			
				if(empty($pd['kick'])) $pd['kick'] = null;			
				if(empty($pd['seconds_played_in_last_90_days'])) $pd['seconds_played_in_last_90_days'] = null;			
	
				// Do an insert of the player data
				$params = array(
					':uuid' => $uuid, 
					':mc_server_id' => $mc_server_id, 
					':first_login' => date('Y-m-d H:i:s', $pd['first_login']), 
					':last_logout' => date('Y-m-d H:i:s', $pd['last_logout']), 
					':seconds_played' => $pd['seconds_played'], 
					':seconds_played_in_last_90_days' => $pd['seconds_played_in_last_90_days'],
					':days_active' => serialize($pd['days_active']), 
					':chat_messages' => serialize($pd['chat_messages']), 
					':current_nickname' => $pd['current_nickname'], 
					':nicknames' => serialize($pd['nicknames']), 
					':favorite_commands' => serialize($pd['favorite_commands']),
					':most_denied_commands' => serialize($pd['most_denied_commands']),
			       		':deaths' => serialize($pd['deaths']),
					':kicks' => serialize($pd['kick']),
				);
				try {
					$stmt = $this->dbh->prepare('INSERT INTO player_data (player_uuid, mc_server_id, updated, first_login, last_logout, seconds_played, seconds_played_in_last_90_days, days_active, chat_messages, current_nickname, nicknames, favorite_commands, most_denied_commands, deaths, kicks) VALUES (:uuid, :mc_server_id, NOW(), :first_login, :last_logout, :seconds_played, :seconds_played_in_last_90_days, :days_active, :chat_messages, :current_nickname, :nicknames, :favorite_commands, :most_denied_commands, :deaths, :kicks)');
					$stmt->execute($params);
				} catch (PDOException $e) {
					$message = 'Trouble inserting player data with uuid: '.$uuid.'. SQL Error "'.$e->getMessage().'"';
					$this->warnings[] = $message;
					echo $message.PHP_EOL;
				}
			}
			return true;
		}
		
	}
}
