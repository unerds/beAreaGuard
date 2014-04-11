<?php
/*
 __PocketMine Plugin__
name=beAreaGuard
description=Private Area Protection with Builder Privileges
version=1.1
author=Blue Electric
class=beAreaGuard
apiversion=11
*/

$checkcount = 0;
$checktime = 0;

//Protected area
class Area
{
	//Owner's name, name of the world, the first point, second point, an authorized person (AllowedPerson), full availability visit
	public $ownername,$worldname,$ax,$ay,$az,$bx,$by,$bz,$allowed,$visitall,$noy;
	
	public function __construct()
	{
		//Initialization
		$this->ownername = "";
		$this->worldname = "world";
		$this->ax = 0;
		$this->ay = 0;
		$this->az = 0;
		$this->bx = 0;
		$this->by = 0;
		$this->bz = 0;
		$this->allowed = array();
		$this->visitall = false;
	}
	
	//For the calculation of a? <B? Sort (b is greater than a)
	public function soft()
	{
		$temp = max($this->ax, $this->bx);
		$this->ax = min($this->ax, $this->bx);
		$this->bx = $temp;
		
		$temp = max($this->ay, $this->by);
		$this->ay = min($this->ay, $this->by);
		$this->by = $temp;
		
		$temp = max($this->az, $this->bz);
		$this->az = min($this->az, $this->bz);
		$this->bz = $temp;
	}
	
	//Targets x, y, z in the protected area Verify
	public function checkInside($x, $y, $z)
	{
		//global $checkcount, $checktime;
		//$start = microtime();
		//$checkcount += 1;
		//console("checkInside: xyz:" . $x . "." . $y . "." . $z . " axyz:" . $this->ax . "." . $this->ay . "." . $this->az . " bxyz:" . $this->bx . "." . $this->by . "." . $this->bz);
		if($this->ax <= $x && $this->bx >= $x &&
			( ( $this->ay <= $y && $this->by >= $y ) || $this->noy) &&
			$this->az <= $z && $this->bz >= $z)
		{
			//$checktime += (microtime() - $start);
			return true;
		}
		//$checktime += (microtime() - $start);
		return false;
	}
}

//Visit / Construction who are allowed to (Area->allowed)
class AllowedPerson
{
	//Availability of construction, can basically visit
	public $build = false;
}

//Specify the area who are
class AreaBuilder
{
	public $builder = ""; //His name is being created
	public $world = ""; //The name of the first selected area
	public $noy = false;
	private $building; //Area being created
	private $second = false; //Whether re-set point twice
	private $complete = false; //Completed set point
	
	//In space, the constructor and owner differ. Therefore, the constructor and owner handled differently
	public function __construct($maker, $owner)
	{
		$this->builder = $maker;
		$this->building = new Area();
		$this->building->ownername = $owner;
	}
	
	//Point designation
	public function setPoint($x,$y,$z)
	{
		//Complete hoping to say anything
		if($this->complete) { return; }
		
		//Select a point in the second, the second
		if($this->second)
		{
			$this->building->bx = $x;
			$this->building->by = $y;
			$this->building->bz = $z;
			$this->complete = true; //selected
		}
		else //If not, select the first area
		{
			$this->building->ax = $x;
			$this->building->ay = $y;
			$this->building->az = $z;
			$this->second = true; //Select the second region
		}
	}
	
	//Builder made ​​(are making) Area return
	public function getResult()
	{
		$this->building->worldname = $this->world;
		return $this->building; //Building return
	}
	
	//Confirm completion
	public function isComplete()
	{
		return $this->complete;
	}
}

class BadAccesser
{
	public $tick, $name;
	
	public function __construct($name)
	{
		$this->name = $name;
	}
}

//For plug-in class
class beAreaGuard implements Plugin
{
	//API Objects
	private $api;
	//Working folder
	private $path;
	//List of Protected Areas
	private $areas;
	//List of people who are protected areas
	private $areasetters;
	//Unauthorized person on the protected area list entry
	private $badaccessers;
	//Check your user list
	private $checkplayers;
	
	//Other settings
	//list
	//area_whiteworld = array( $string = username that builder on whiteworld );
	private $config;

	//Plug-in Constructor
	public function __construct(ServerAPI $api, $server = false)
	{
		//Api to use for the server to receive the object coming api
		$this->api = $api;
		//Time to repair
		date_default_timezone_set("Asia/Seoul");
	}
	
	//Plug-in initialization,
	public function init()
	{
		//Import folder for work
		$this->path = $this->api->plugin->configPath($this);
		
		//Registered handler to receive commands and
		//Space designation
		$this->api->console->register("AreaSet", "<player> <No Y> Define a private area for <player> or self.", array($this, "cmdHandler"));
		//Remove the space
		$this->api->console->register("AreaDel", "Delete private in which you are currently standing.", array($this, "cmdHandler"));
		//Create the specified space area
		$this->api->console->register("AreaMake", "Commit private area created with /AreaSet.", array($this, "cmdHandler"));
		//Choose your space, abandoned
		$this->api->console->register("AreaCancel", "Cancel selection of private area.", array($this, "cmdHandler"));
		//Access to specific users / construction permit
		$this->api->console->register("AreaAllow", "[Name|All] <Build|Delete> Add or remove build rights of a <player> for the area in which you are standing.", array($this, "cmdHandler"));
		//Add to Whitelist are world
		$this->api->console->register("AreaWorld", "<Delete> Enable/disable build privilege whiteworld.", array($this, "cmdHandler"));
		//Currently unprotected areas in the world who can add, modify
		$this->api->console->register("AreaBuilder", "[Name] <Delete> Enable/disable build privileges for <player> in whiteworld.", array($this, "cmdHandler"));
		//$this->api->console->register("AreaSet", "Set Area for Someone or Self", array($this, "cmdHandler"));
		
		$this->api->ban->cmdWhitelist("areadel");
		$this->api->ban->cmdWhitelist("areaallow");
		
		//The action you handler to handle user registration and
		$this->api->addHandler("tile.update", array($this, "handler"));
		$this->api->addHandler("entity.move", array($this, "handler"));
		$this->api->addHandler("player.quit", array($this, "handler"));
		$this->api->addHandler("player.block.activate", array($this, "handler"));
		$this->api->addHandler("player.block.place", array($this, "handler"));
		$this->api->addHandler("player.block.break", array($this, "handler"));
		$this->api->addHandler("player.block.touch", array($this, "handler"));
		
		//data
		//x -> check x
		//y -> check y
		//z -> check z
		//result : exist 'forsell' space for given position(x,y,z)
		$this->api->addHandler("be.monitor.sellspace.exist", array($this, "handler"));
		
		//data
		//x -> pos x
		//y -> pos y
		//z -> pos z
		//buyer -> Player->username
		$this->api->addHandler("be.monitor.sellspace.buy", array($this, "handler"));
		
		//Accessor for each protected area, check a specific time
		$this->api->schedule(20, array($this, "tick"), array(), true);
		
		//Initializing the list of
		$this->areasetters = array();
		$this->badaccessers = array();
		$this->checkplayers = array();
		$this->traces = array();
		
		//From the file brings up a list of which was recorded before
		//If the file does not exist, an empty list is set to
		$this->readArea();
		$this->readConfig();
	}
	
	public function __destruct()
	{
		
	}
	
	public function readArea()
	{
		//If no local file, an empty list, if you recall
		if(!file_exists($this->path . "area.bin")) { $this->areas = array(); return; }
		$result = file_get_contents($this->path . "area.bin");
		$this->areas = unserialize($result);
	}
	
	public function writeArea()
	{
		//A list of regions
		file_put_contents($this->path . "area.bin", serialize($this->areas));
	}
	
	public function readConfig()
	{
		//If the default configuration file, if you recall
		if(!file_exists($this->path . "config.bin"))
		{
			$this->config = array();
			$this->config["area_whiteworld"] = array();
			return;
		}
		$result = file_get_contents($this->path . "config.bin");
		$this->config = unserialize($result);
	}
	
	public function writeConfig()
	{
		//Creating a configuration file
		file_put_contents($this->path . "config.bin", serialize($this->config));
	}
	
	public function tick()
	{
		$online = $this->api->player->online();
		
		//10 people found
		//20 Protection
		foreach($online as $name)
		{
			$player = $this->api->player->get($name);
			if($player === false) { unset($this->badaccessers[$name]); continue; }
			
			if($this->api->ban->isOP( $player ) ) { continue; }
			
			foreach ($this->areas as $area)
			{
				if(	$player->level->getName() != $area->worldname ||
					$area->ownername == $player->username || $area->visitall ||
					$area->ownername == "forsell" || isset($area->allowed[ $player->username ]) )
				{
					continue;
				}
				
			}
		}
	}
	
	public function cmdHandler($cmd, $argments, $issuer, $alias)
	{
		return "[-] " . $this->cmdHandle($cmd, $argments, $issuer, $alias);
	}
	
	//Command handling
	public function cmdHandle($cmd, $argments, $user, $alias)
	{
		switch ($cmd)
		{
			case "areaset": //Regional setting
				//Only players that write this command (because you break blocks)
				if ( $user instanceof Player ){ }
				else{
					return "This Command Is In-Game Only";
				}
				//Make sure that the current setting
				foreach ($this->areasetters as &$setter)
				{
					//Instruction during the current list boasts setter if the same person
					//In other words, again, try to choose people who are already selected hangyeongwoo
					if($setter->builder == $user->username)
					{
						//Warning
						return "You're Already Selecting An Area";
					}
				}
				//If the value specified by the owner to use a separate
				//If not, use the name of the requester
				$owner = "";
				if(isset($argments[0]))
				{
					$owner = $argments[0];
				}
				else
				{
					$owner = $user->username;
				}
				$builder = new AreaBuilder($user->username, $owner);
				if(isset($argments[1]))
				{
					$builder->noy = true;
				}
				//Space set start
				$this->areasetters[] = $builder;
				return "Start Select Area for " . $owner;
				break;
			case "areamake":
				//Command from the list of found users setter
				foreach ($this->areasetters as $key => &$setter)
				{
					if($setter->builder == $user->username)
					{
						//If you find it, make sure to complete the selection
						if($setter->isComplete())
						{
							//Added to the list of completed, sort and space
							$setter->getResult()->soft();
							$setter->getResult()->noy = $setter->noy;
							$this->areas[] = $setter->getResult();
							$this->writeArea();
							//Remove the player from the list constructor
							unset( $this->areasetters[$key] );
							//Add a completion notification
							return "Area Created with Your Selection";
						}
						else
						{
							//If no selections have been warnings
							return "Select Not Completed";
						}
					}
				}
				//If you found, return None can come here to meet
				//Therefore, because it is not found, the notification
				return "You Are Not Selecting";
				break;
			case "areacancel":
				foreach ($this->areasetters as $key => &$setter)
				{
					if($setter->builder == $user->username)
					{
						unset( $this->areasetters[$key] );
						return "Your Selection Ignored, /areaset for Reselect";
					}
				}
				return "You Are Not Selecting";
				break;
			case "areaallow":
				if ( $user instanceof Player ){ }
				else{
					return "This Command In-Player Only";
				}
				if ( !isset($argments[0]) )
				{
					return "Usage: /areaallow [Name|All] [Build|Delete]";
				}
				
				foreach ($this->areas as &$area)
				{
					if( $area->checkInside($user->entity->x, $user->entity->y, $user->entity->z) )
					{
						if($area->ownername != $user->username)
						{
							return "This Area Not for You!";
						}
						else
						{
							if(!isset($argments[1]))
							{
								$argments[1] = "";
							}
							switch ( strtolower($argments[0]) )
							{
								case "all":
									switch ( strtolower($argments[1]) )
									{
										case "build":
											return "You Are Kidding? Use /areadel for Unprotect the Area";
											break;
										case "delete":
											$area->visitall = false;
											$this->writeArea();
											return "Your Request Processed";
											break;
										default:
											$area->visitall = true;
											$this->writeArea();
											return "Your Request Processed";
											break;
									}
									break;
								default:
									switch ( strtolower($argments[1]) )
									{
										case "build":
											if(isset($area->allowed[ $argments[0] ]))
											{
												$area->allowed[ $argments[0] ]->build = true;
											}
											else
											{
												$allowed = new AllowedPerson();
												$allowed->build = true;
												$area->allowed[ $argments[0] ] = $allowed;
											}
											$this->writeArea();
											return "Your Request Processed";
											break;
										case "delete":
											if( !isset($area->allowed[ $argments[0] ]) )
											{
												return "Unknown Allowed Person";
											}
											unset($area->allowed[ $argments[0] ]);
											$this->writeArea();
											return "Your Request Processed";
											break;
										default:
											if(isset($area->allowed[ $argments[0] ]))
											{
												return "Already Allowed Person";
											}
											$area->allowed[ $argments[0] ] = new AllowedPerson();
											$this->writeArea();
											return "Your Request Processed";
											break;
									}
									break;
							}
						}
					}
				}
				return "Must be standing INSIDE your protected area.";
				break;
			case "areadel":
				if ( $user instanceof Player ){ }
				else{
					return "This Command In-Player Only";
				}
				foreach ($this->areas as $key => &$area)
				{
					if( $area->checkInside($user->entity->x, $user->entity->y, $user->entity->z) )
					{
						if($area->ownername != $user->username && !$this->api->ban->isOP( $user ))
						{
							return "This Area Not for You!";
						}
						else
						{
							unset($this->areas[$key]);
							$this->writeArea();
							return "Your Request Processed";
						}
					}
				}
				return "Must be standing INSIDE your protected area.";
				break;
			case "areaworld":
				if ( $user instanceof Player ){ }
				else {
					return "This Command In-Player Only";
				}
				
				if( isset( $this->config["area_whiteworld"][$user->level->getName()] ) )
				{
					if( isset( $argments[0] ) )
					{
						unset( $this->config["area_whiteworld"][$user->level->getName()] );
						$this->writeConfig();
						return $user->level->getName() . " No longer WhiteWorld";
					}
					return "Already Protected";
				}
				else
				{
					if( isset( $argments[0] ) )
					{
						return $user->level->getName() . " Not WhiteWorld";
					}
					$this->config["area_whiteworld"][$user->level->getName()] = array();
					$this->writeConfig();
					return "Added to WhiteWorld";
				}
				break;
			case "areabuilder":
				if ( $user instanceof Player ){ }
				else {
					return "This Command In-Player Only";
				}
				
				if( !isset( $argments[0] ) ) { return "Invalid"; }
			
				if( isset( $argments[1] ) )
				{
					foreach ($this->config["area_whiteworld"][$user->level->getName()] as $key => $builder)
					{
						if($builder == $user->username)
						{
							unset( $this->config["area_whiteworld"][$user->level->getName()][$key] );
							$this->writeConfig();
						}
					}
					return "Removed";
				}
			
				if( isset( $this->config["area_whiteworld"][$user->level->getName()] ) )
				{
					$this->config["area_whiteworld"][$user->level->getName()][] = $argments[0];
					$this->writeConfig();
					return $argments[0] . " Added to Builder on This World";
				}
				else
				{
					return "Your World Not WhiteWorld";
				}
				break;
		}
		
		return "Unknown Command";
	}
	
	public function handler($data, $event)
	{
		switch($event)
		{
			case "player.block.activate":
			case "player.block.place":
			case "player.block.break":
			case "player.block.touch":
			$player = $data["player"];
			$target = null;
			if($event == "player.block.place")
			{
				$target = $data["block"];
			}
			else
			{
				$target = $data["target"];
			}
			
			if($target->getID() == SIGN_POST && $event == "player.block.touch")
			{
				break;
			}
			
			//Immutable space
			foreach ($this->areas as &$area)
			{
				if($player->level->getName() != $area->worldname || $area->ownername == $player->username)
				{
					continue;
				}
				else if( $this->api->ban->isOP($player) && $area->ownername == "forsell" )
				{
					continue;
				}
				$know = false;
				foreach($area->allowed as $key => $allowed)
				{
					if($key == $player->username)
					{
						$know = true;
						break;
					}
				}
				if($know) { continue; }
				if($area->checkInside($target->x, $target->y, $target->z))
				{
					$this->chatTo($player, "This Area Protected by " . $area->ownername);
					return false;
				}
			}
			if( isset( $this->config["area_whiteworld"][$player->level->getName()] ) )
			{
				$issetter = false;
				foreach ( $this->config["area_whiteworld"][$player->level->getName()] as $builder)
				{
					if($player->username == $builder)
					{
						$issetter = true;
						break;
					}
				}
				foreach ($this->areasetters as &$setter)
				{
					if($issetter) { break; }
					//console("setter:" . $setter->builder . " player:" . $player->username);
					if($setter->builder == $player->username)
					{
						$issetter = true;
						break;
					}
				}
				if(!$issetter)
				{
					foreach ($this->areas as &$area)
					{
						if($area->ownername != $player->username) { continue; }
						if($area->checkInside($target->x, $target->y, $target->z))
						{
							goto end;
						}
					}
					$this->chatTo($player, "You Not Allowed to Access/Edit This Block");
					$this->chatTo($player, "This World is WhiteWorld, Build In Your Area");
					return false;
				}
			}
			break;
		}
		
		next:
		
		switch($event)
		{
			case "player.quit":
				unset( $this->checkplayers[ $data->username ] );
				break;
			case "player.block.break":
				//블럭 활동 기록
				$player = $data["player"];
				$target = $data["target"];
				if( strtolower( $target->getName() ) == "air")
				{
					continue;
				}
				
				//Process space setters
				foreach ($this->areasetters as &$setter)
				{
					//console("setter:" . $setter->builder . " player:" . $player->username);
					if($setter->builder == $player->username)
					{
						if($setter->isComplete())
						{
							$this->chatTo($player, "You already selected two points for area.");
							$this->chatTo($player, "Type '/areamake' to commit or '/areacancel' to start over.");
							return false;
						}
						else
						{
							$x = $target->x;
							$y = $target->y;
							$z = $target->z;
							$setter->setPoint($x, $y, $z);
							if($setter->isComplete())
							{
								if($setter->world != $player->level->getName())
								{
									$this->chatTo($player, "Warning: Second point selected in another world");
								}
								$this->chatTo($player, "2nd Point(" . $x . "," . $y . "," . $z . ")");
								$this->chatTo($player, "You Just Selected Two Point for Area");
								$this->chatTo($player, "Type '/areamake' or '/areacancel' to complete selection");
							}
							else
							{
								$this->chatTo($player, "1st Point(" . $x . "," . $y . "," . $z . ")");
								$this->chatTo($player, "Please select 2nd point");
								$setter->world = $player->level->getName();
							}
						}
						return false;
					}
				}
				
				break;
			case "be.monitor.sellspace.exist":
				if( !isset($data["x"]) || !isset($data["y"]) || !isset($data["z"]) )
				{
					var_dump($data);
					console("[-]Bad Handle");
					return false;
				}
				foreach($this->areas as &$area)
				{
					if($area->ownername == "forsell" && $area->checkInside($data["x"], $data["y"], $data["z"]))
					{
						return true;
					}
				}
				return false;
				break;
			case "be.monitor.sellspace.buy":
				if( !isset($data["x"]) || !isset($data["y"]) || !isset($data["z"]) || !isset($data["buyer"]) )
				{
					var_dump($data);
					console("[-]Bad Handle");
					return false;
				}
				foreach($this->areas as &$area)
				{
					if($area->ownername == "forsell" && $area->checkInside($data["x"], $data["y"], $data["z"]))
					{
						$area->ownername = $data["buyer"];
						$this->writeArea();
						return true;
					}
				}
				return false;
				break;
		}
		end:
	}
	
	public function chatTo($player, $chat)
	{
		$player->sendChat("[-]" . $chat);
	}
}

?>