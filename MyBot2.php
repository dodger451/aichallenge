<?php

require_once 'Ants.php';

function distsort($a, $b)
{
    if ($a[0] == $b[0]) {
        return 0;
    }
    return ($a[0] < $b[0]) ? -1 : 1;
} 

function myErrorHandler($fehlercode, $fehlertext, $fehlerdatei, $fehlerzeile)
{
	$log = new KLogger ( "error.log" , KLogger::DEBUG );

    switch ($fehlercode) {
		case E_USER_ERROR:
			$log->logError( "[$fehlercode] $fehlertext");
			$log->logError( "in $fehlerdatei,  row $fehlerzeile");
			
			exit(1);
			break;

		case E_USER_WARNING:
			$log->logWarn( "[$fehlercode] $fehlertext");
			$log->logWarn( "in $fehlerdatei,  row $fehlerzeile");
			break;

		case E_USER_NOTICE:
			$log->logInfo( "[$fehlercode] $fehlertext");
			$log->logInfo( "in $fehlerdatei,  row $fehlerzeile");
			break;

		default:
			$log->logInfo( "Unbekannter Fehlertyp: [$fehlercode] $fehlertext" );
			$log->logInfo( "in $fehlerdatei,  row $fehlerzeile");
			break;
    }

    /* Damit die PHP-interne Fehlerbehandlung nicht ausgeführt wird */
    return true;
}
$alter_error_handler = set_error_handler("myErrorHandler");

class MyBot
{
    private $directions = array('n','e','s','w');
	private $ants = null;
	private $orders = null;
	private $targets = null;
	private $unseen = null;
	private $hills = null;
	
	private $log = null;
	
	private $setupDone = false;
	
	public function __construct(  )
	{
	
		$this->log = new KLogger ( "bot.log" , KLogger::DEBUG );
		$this->setupDone = false;
	}
	public function doSetup(  )
    {	
		$this->unseen = array();
		for ( $i=0; $i<$this->ants->rows; $i++ ) {
				for ( $j=0; $j<$this->ants->cols; $j++ ) {
					$this->unseen[$i][$j] = true;
				}
		}
		
		$this->hills = array();
	}
    public function doTurn( $ants )
    {	
		$this->ants = $ants;
		$this->orders = array();
		$this->targets = array();
		
		if ( !$this->setupDone ) {
			$this->doSetup();
			$this->setupDone = true;
		}
		
		# prevent stepping on own hill
		foreach ( $this->ants->myHills as $hill ) {
			$this->orders[$hill[0]][$hill[1]] = array(null,null);
		}

		//move to food
		//for each food-item, find distance to our ants
		$antDist = array();
		foreach ( $this->ants->food as $foodLocation ) {
			foreach ( $this->ants->myAnts as $ant ) {
				list ($aRow, $aCol) = $ant;
				list ($dRow, $dCol) = $foodLocation;
				$dist = $this->ants->distance($aRow, $aCol, $dRow, $dCol);
				$antDist[] = array($dist, $ant, $foodLocation);				
			}
		}
		//foreach ant/food combination, move ant to food 
		//if ant has nothing to do and no other ant goes there
		usort($antDist, 'distsort');	
		foreach ($antDist as $k) {
			list ($dist, $ant, $foodLocation) = $k;
			list ($dRow, $dCol) = $foodLocation;
				
			if (!isset($this->targets[$dRow][$dCol]) && !$this->antHasTarget($ant) ) {
				$this->doMoveLocation($ant, $foodLocation);
			}
		}

		//attack hills
		//update list of known hills
		foreach ( $this->ants->enemyHills as $hill ) {
			list($hRow, $hCol) = $hill;
			if(!isset($this->hills[$hRow][$hCol])) {
				$this->hills[$hRow][$hCol] = true;
			}
		}
		//get distances from orderless ants to hills
		$antDist = array();
		foreach ( $this->hills as $hRow => $rData ) {
			foreach ( $rData as $hCol => $boolTrue ) {		
				foreach ( $this->ants->myAnts as $ant ) {
					if( !$this->antHasOrder($ant) ) {
						list ($aRow, $aCol) = $ant;
						$dist = $this->ants->distance($aRow, $aCol, $hRow, $hCol);
						$antDist[] = array($dist, $ant, array($hRow, $hCol));
					}
				}
			}
		}
		//move orderless ants to closest hill
		$this->log->logDebug('move orderless ants to closest hill');
		usort($antDist, 'distsort');	
		foreach ( $antDist as $distAntHill ) {
			list ($dist, $ant, $hill) = $distAntHill;
			//$this->log->logDebug('move orderless ant from pos ['.$ant[0].', '.$ant[1].'] to closest hill at pos ['.$hill[0].', '.$hill[1].']');
			$this->doMoveLocation($ant, $hill);
		}
		
		
		
		//explore unseen areas
		//update list of onseen tiles
		$newUnseen = $this->unseen;
		foreach ( $this->unseen as $rowIdx => $rData ) {
			foreach ( $rData as $colIdx => $boolTrue ) {
				if ( $this->visible(array($rowIdx, $colIdx))) {
					unset($newUnseen[$rowIdx][$colIdx]);
				}
			}
		}
		$this->unseen = $newUnseen;
		//move each orderless ant to closet unseen tile
		foreach ( $this->ants->myAnts as $ant ) {
			list ($aRow, $aCol) = $ant;
			if ( !$this->antHasOrder($ant) ) {
				$unseenDist = array();
				foreach ( $this->unseen as $row => $rData ) {
					foreach ( $rData as $col => $boolTrue ) {
						$dist = $this->ants->distance($aRow, $aCol, $row, $col);
						$unseenDist[] = array($dist, $row, $col);
					}
				}
				usort($unseenDist, 'distsort');
				foreach ( $unseenDist as $cUnseenDist ) {
					list($dist, $row, $col) = $cUnseenDist;
					if ( $this->doMoveLocation($ant, array($row, $col)) )
						break;
				}
			}
		}
		
		//unblock hills: move orderless ants that stand on hills
		foreach ( $this->ants->myHills as $hill ) {
			if ( $this->antAt($hill) && !$this->antHasOrder($hill) ) {
				foreach ($this->directions as $direction) {
					if($this->doMoveDirection($hill, $direction)) 
						break;
				}
			}
		}
    }
	protected function antAt( $loc ) {
		foreach ( $this->ants->myAnts as $ant ) 
			if ( $loc[0] == $ant[0] && $loc[1] == $ant[1] ) 
				return true;
		return false;
	}


	protected function antHasTarget( $ant ) {
		return $this->antInArray($this->targets, $ant);
	}

	protected function antHasOrder( $ant ) {
		return $this->antInArray($this->orders, $ant);
	}
	
	protected function antInArray( $array, $ant ) {
		list ($aRow, $aCol) = $ant;
		
		foreach ( $array as $col )
			foreach ( $col as $row ){
				list ($dRow, $dCol) = $row;
				if( $dRow == $aRow && $dCol == $aCol)
					return true;
			}
		return false;
	}
	
	protected function visible( $location ) {
		foreach ( $this->ants->myAnts as $ant ) 
			if ( $this->locationInViewRangeOfAnt($location, $ant) ) 
				return true;
		return false;
	}
	protected function locationInViewRangeOfAnt( $location , $ant ) {
		return $this->inRangeOf($location, $ant, $this->ants->viewradius2);
	}
	
	protected function inRangeOf( $loc1, $loc2, $squaredRange2) {
		list ($row1, $col1) = $loc1;
		list ($row2, $col2) = $loc2;
						
		$dRow = abs($row1 - $row2);
        $dCol = abs($col1 - $col2);
        $dRow = min($dRow, $this->ants->rows - $dRow);
        $dCol = min($dCol, $this->ants->cols - $dCol);

		return ($dRow * $dRow + $dCol * $dCol) <= $squaredRange2;
	}
	
	protected function doMoveDirection( $location, $direction ) 
	{
		list ($aRow, $aCol) = $location;
		list ($dRow, $dCol) = $this->ants->destination($aRow, $aCol, $direction);
                
		if ( $this->ants->unoccupied($dRow, $dCol) && !isset($this->orders[$dRow][$dCol]) ) {
			$this->ants->issueOrder($aRow, $aCol, $direction);
			$this->orders[$dRow][$dCol] = $location;			
			return true;
		} else {
			return false;
		}
	}
	
	protected function doMoveLocation( $location, $destination ) 
	{
		list ($aRow, $aCol) = $location;
		list ($dRow, $dCol) = $destination;
		
		$directions = $this->ants->direction($aRow, $aCol, $dRow, $dCol);
		foreach ($directions as $direction) {
			if ( $this->doMoveDirection($location, $direction) ) {
				$this->targets[$dRow][$dCol] = $location;
				return true;
			} 
		}
		return false;

	}
	/*
	targets = {}
        def do_move_location(loc, dest):
            directions = ants.direction(loc, dest)
            for direction in directions:
                if do_move_direction(loc, direction):
                    targets[dest] = loc
                    return True
            return False
	*/
    
}

/**
 * Don't run bot when unit-testing
 */
if( !defined('PHPUnit_MAIN_METHOD') ) {
    Ants::run( new MyBot() );
}