<?php

class BaseController extends Controller {

    /**
     * Setup the layout used by the controller.
     *
     * @return void
     */
    protected function setupLayout()
    {
        if ( ! is_null($this->layout))
        {
            $this->layout = View::make($this->layout);
        }
    }

    protected abstract function getData($data);
    protected abstract function serveJSON($data,$callback = "");
    protected abstract function serveXML($data);
    protected abstract function serveHTML($data);
    protected abstract function serveKML($data);

    protected $language = "en";

    public function index() {

        

        // first get the data for the resource
        $data = $this->getData();
        // then use this data to serve it

        // first, negotiate the content from the GET parameter
        if ( isset(Input::get("format")) ){
            // support what the old API supported: xml, json and jsonp
            if( strtolower(Input::get("format")) === "xml" ) {
                return $this->serveXML();
            } else if ( strtolower(Input::get("format")) === "json" ||strtolower(Input::get("format")) === "jsonp" ) {
                return $this->serveJSON(Input::get("callback"));
            } else if( strtolower(Input::get("format")) === "kml" ) {
                return $this->serveKML();
            } 
        } else {
            $priorities = array("application/json","text/html","application/xml","*/*");
            $negotiator = new \Negotiation\Negotiator();
            $format = $negotiator->getBest(Request::header('accept'), $priorities);
            if (!isset($format)){
                //sorry, this needs to be xml for historical purposes
                $format = "application/xml";
            }
            
            switch ( strtolower($format) ) {
                
                case "text/html":
                    return $this->serveHTML();
                    break;
                case "application/json":
                    return $this->serveJSON();
                    break;
                case "application/xml";
                default:
                    return $this->serveXML();
                    break;
            }
        }
        
    }

}
