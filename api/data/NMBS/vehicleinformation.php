<?php
/**
 * Copyright (C) 2011 by iRail vzw/asbl
 * Copyright (C) 2015 by Open Knowledge Belgium vzw/asbl
 *
 * This will fetch all vehicledata for the NMBS.
 *
 *   * fillDataRoot will fill the entire dataroot with vehicleinformation
 *
 * @package data/NMBS
 */

include_once("data/NMBS/tools.php");
include_once("data/NMBS/stations.php");
include_once("../includes/simple_html_dom.php");
include_once("../includes/getUA.php");
class vehicleinformation{

    /**
     * @param $dataroot
     * @param $request
     * @throws Exception
     */
    public static function fillDataRoot($dataroot,$request){
        $lang= $request->getLang();

        $serverData = vehicleinformation::getServerData($request->getVehicleId(),$lang);
        $html = str_get_html($serverData);

        // Check if there is a valid result from the belgianrail website
        if (!vehicleinformation::trainDrives($html)) {
            throw new Exception("Route not available.", 404);
        }
        // Check if train splits
        if (vehicleinformation::trainSplits($html)) {
            // Two URL's, fetch serverData from matching URL
            $serverData = vehicleinformation::parseCorrectUrl($html);
            $html = str_get_html($serverData);
        }

        $dataroot->vehicle = vehicleinformation::getVehicleData($html, $request->getVehicleId(), $lang);
        $dataroot->stop = [];
        $dataroot->stop = vehicleinformation::getData($html, $lang, $request->getFast());
    }

    /**
     * @param $id
     * @param $lang
     * @return mixed
     */
    private static function getServerData($id,$lang){
        global $irailAgent; // from ../includes/getUA.php

        $request_options = [
            "referer" => "http://api.irail.be/",
            "timeout" => "30",
            "useragent" => $irailAgent,
        ];
        $scrapeURL = "http://www.belgianrail.be/jp/sncb-nmbs-routeplanner/trainsearch.exe/" . $lang . "ld=std&seqnr=1&ident=at.02043113.1429435556&";
        $id = preg_replace("/[a-z]+\.[a-z]+\.([a-zA-Z0-9]+)/smi", "\\1", $id);

        $post_data = "trainname=" . $id . "&start=Zoeken&selectDate=oneday&date=" . date( 'd%2fm%2fY' ) . "&realtimeMode=Show";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $scrapeURL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_REFERER, $request_options["referer"]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $request_options["timeout"]);
        curl_setopt($ch, CURLOPT_USERAGENT, $request_options["useragent"]);
        $result = curl_exec($ch);

        curl_close ($ch);
        return $result;
    }


    /**
     * @param $html
     * @param $lang
     * @param $fast
     * @return array
     * @throws Exception
     */
    private static function getData($html, $lang, $fast){
        try {
            $stops = [];
            $nodes = $html->getElementById('tq_trainroute_content_table_alteAnsicht')
                ->getElementByTagName('table')
                ->children;


            $j = 0;
            for ($i=1; $i < count($nodes); $i++) {
                $node = $nodes[$i];
                if (!count($node->attr)) continue; // row with no class-attribute contain no data

                $delaynodearray = $node->children[2]->find('span');
                $delay = count($delaynodearray) > 0 ? trim(reset($delaynodearray[0]->nodes[0]->_)) : "0";
                $delayseconds = preg_replace("/[^0-9]/", '', $delay)*60;

                $spans = $node->children[1]->find('span');
                $arriveTime = reset($spans[0]->nodes[0]->_);
                $departureTime = count($nodes[$i]->children[1]->children) == 3 ? reset($nodes[$i]->children[1]->children[0]->nodes[0]->_) : $arriveTime;

                if (count($node->children[3]->find('a'))) {
                    $as = $node->children[3]->find('a');
                    $stationname = reset($as[0]->nodes[0]->_);
                }

                else $stationname = reset($node->children[3]->nodes[0]->_);

                $stops[$j] = new Stop();
                $station = new Station();
                if ($fast == "true"){
                    $station->name = $stationname;
                } else {
                    // Station ID can be parsed from the station URL
                    if (isset($node->children[3]->children[0])) {
                        $link = $node->children[3]->children[0]->{'attr'}['href'];
                        // With capital S
                        if (strpos($link, 'StationId=')) {
                          $nr = substr($link, strpos($link, 'StationId=') + strlen('StationId='));
                        } else {
                          $nr = substr($link, strpos($link, 'stationId=') + strlen('stationId='));
                        }
                        $nr = substr($nr, 0, strlen($nr) - 1); // delete ampersand on the end
                        $stationId = '00'.$nr;
                        $station = stations::getStationFromID($stationId,$lang);
                    } else {
                        $station = stations::getStationFromName($stationname,$lang);
                    }
                }
                $stops[$j]->station = $station;
                $stops[$j]->delay = $delayseconds;
                $stops[$j]->time = tools::transformTime("00d" . $departureTime . ":00", date("Ymd"));

                $j++;
            }

            return $stops;
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 500);
        }
    }

    /**
     * @param $html
     * @param $id
     * @param $lang
     * @return null|Vehicle
     * @throws Exception
     */
    private static function getVehicleData($html, $id, $lang){
        // determine the location of the vehicle
        $test = $html->getElementById('tq_trainroute_content_table_alteAnsicht');
        if (!is_object($test))
            throw new Exception("Vehicle not found", 1); // catch errors

        $nodes = $html->getElementById('tq_trainroute_content_table_alteAnsicht')->getElementByTagName('table')->children;

        for($i=1; $i<count($nodes); $i++){
            $node = $nodes[$i];
            if (!count($node->attr)) continue; // row with no class-attribute contain no data

            if (count($node->children[3]->find('a'))) {
                $as = $node->children[3]->find('a');
                $stationname = reset($as[0]->nodes[0]->_);
            } else {
                // Foreign station, no anchorlink
                $stationname = reset($node->children[3]->nodes[0]->_);
            }

            $locationX = 0;
            $locationY = 0;
            // Station ID can be parsed from the station URL
            if (isset($node->children[3]->children[0])) {
                $link = $node->children[3]->children[0]->{'attr'}['href'];
                // With capital S
                if (strpos($link, 'StationId=')) {
                  $nr = substr($link, strpos($link, 'StationId=') + strlen('StationId='));
                } else {
                  $nr = substr($link, strpos($link, 'stationId=') + strlen('stationId='));
                }
                $nr = substr($nr, 0, strlen($nr) - 1); // delete ampersand on the end
                $stationId = '00'.$nr;
                $station = stations::getStationFromID($stationId,$lang);
            } else {
                $station = stations::getStationFromName($stationname,$lang);
            }
            
            if (isset($station)){
                $locationX = $station->locationX;
                $locationY = $station->locationY;
            }
            $vehicle = new Vehicle();
            $vehicle->name = $id;
            $vehicle->locationX = $locationX;
            $vehicle->locationY = $locationY;
            return $vehicle;
        }

        return null;
    }

    private static function trainSplits($html) {
        return !is_object($html->getElementById('tq_trainroute_content_table_alteAnsicht'));
    }

    private static function trainDrives($html) {
        return is_object($html->getElementById('HFSResult')->getElementByTagName('table'));
    }

    private static function parseCorrectUrl($html) {
        $test = $html->getElementById('HFSResult')->getElementByTagName('table');
        if (!is_object($test))
            throw new Exception("Vehicle not found", 1); // catch errors

        // Try first url
        $url = $html->getElementById('HFSResult')
            ->getElementByTagName('table')
            ->children[1]->children[0]->children[0]->attr['href'];

        $serverData = vehicleinformation::getServerDataByUrl($url);

        // Check if no other route id in trainname column
        if (vehicleinformation::isOtherTrain($serverData)) {
            // Second url must be the right one
            $url = $html->getElementById('HFSResult')
                ->getElementByTagName('table')
                ->children[2]->children[0]->children[0]->attr['href'];

            $serverData = vehicleinformation::getServerDataByUrl($url);
        }

        return $serverData;
    }

    private static function isOtherTrain($serverData) {
        $html = str_get_html($serverData);
        $originalTrainname = null;

        $nodes = $html->getElementById('tq_trainroute_content_table_alteAnsicht')
            ->getElementByTagName('table')
            ->children;

        for ($i=1; $i < count($nodes); $i++) {
            $node = $nodes[$i];
            if (!count($node->attr)) continue; // row with no class-attribute contain no data

            $trainname = str_replace(' ', '', reset($node->children[4]->nodes[0]->_));
            if (!is_object($originalTrainname)) {
                $originalTrainname = $trainname;
            } else if ($trainname != '&nbsp;' && $trainname != $originalTrainname) {
                // This URL returns route of the other train
                return true;
            }
        }

        return false;
    }

    private static function getServerDataByUrl($url) {
        global $irailAgent; // from ../includes/getUA.php

        include_once("../includes/getUA.php");
        $request_options = [
            "referer" => "http://api.irail.be/",
            "timeout" => "30",
            "useragent" => $irailAgent
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_REFERER, $request_options["referer"]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $request_options["timeout"]);
        curl_setopt($ch, CURLOPT_USERAGENT, $request_options["useragent"]);
        $result = curl_exec($ch);

        curl_close ($ch);
        return $result;
    }
};
