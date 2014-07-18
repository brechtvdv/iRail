<?php

class RedirectController extends \BaseController {

    /**
     * Redirect towards the API documentation
     *
     * @return Response
     */
    public function index() 
    {
        return Redirect::to('http://project.irail.be/wiki/API/APIv1');
    }

}

