<?php

namespace App\Http\Controllers;


use Illuminate\Http\Response;
use STS\ZipStream\Facades\Zip;

class ZipController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function __invoke()
    {
        return Zip::create('zipFileName.zip', File::files(public_path('myFiles')));
    }
}
