<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Log;
use Google_Exception;
use Google_Service_Exception;
use Carbon\Carbon;
use Exception;
use Google_Service_Calendar_Event;
use App\User;
use Nataliaalves\LaravelCalendar\Facade\LaravelCalendar;
use Nataliaalves\LaravelCalendar\Services\Event\Invite as LaravelInvite;


class GoogleCalendarController extends Controller
{
    private $service = null;
    private $user = null;
    
    function __construct(User $user = null){
        
        if(!empty($user))
            $this->user = $user;
            $this->service = $this->createServiceCalendar($user);
    }


    /**
     * Create an authenticated Google Service calendar
     */
    public function createServiceCalendar(User $user){
        try{
            //user by token - handle auth if needed
            $token = $this->getTokenCalendar($user);

            //Laravel Calendar instance
            $client = new LaravelInvite();

            //set user token
            if(is_array($token))
                $client = $client->using($token);
            
            //return service Google_Service_Calendar
            if(property_exists($client, 'service') && get_class($client->service) == 'Google_Service_Calendar'){
                $this->user = $user;
                return $client->service;
            }
        }
        catch(Google_Exception | Throwable | Exception $e){
            if(get_class($e) == 'Symfony\Component\HttpKernel\Exception\HttpException' && $e->getStatusCode() == 403){
                Log::info(__CLASS__." User not allowed (403): ".$user->id);
                
                //disable user's calendar
                $this->setStatusAgenda(false, $user->id);
            }else{
                Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
            }
        }

        return null;
    }


     /** 
      * Auth Calendar API 
      */
     public function authCalendar(){
        //store previous url to use during callback
        session(['auth-partner-redirect' => url()->previous()]);

        return LaravelCalendar::redirect();
    }


    /** 
     * Auth Google Calendar callback 
     */
    public function authCalendarCallback(){
        try{
            LaravelCalendar::makeToken();
        }
        catch(Google_Exception $e){
            return 'google error. '.$e->getMessage();
        }
        catch(Exception $e){
            return 'error while creating google calendar token. '.$e->getMessage();
        }
        
        //back to the previous url
        $url = session('auth-partner-redirect');
        if($url == route('auth.calendar')) $url = '/';
        return redirect()->to($url);
    }

    /**
     *  Logout Google Calendar
     */
    public function logoutCalendar(){
        try{
            LaravelCalendar::logout();
            $this->setStatusAgenda(false);
        }
        catch(AuthException $e){
            return 'auth error. '.$e->getMessage();
        }
        catch(Google_Exception $e){
            return 'google error. '.$e->getMessage();
        }
        catch(Exception $e){
            return 'error. '.$e->getMessage();
        }

        //return to My Account
        return response()->json(['status' => true, 'msg' => 'OK', 'login_route' => route('auth.calendar')]);
    }

    /**
     * Enable or disable user's calendar
     */
    public function setStatusAgenda(bool $status, $user_id = null){
        if(!empty($user_id)){
            $p = User::findOrFail($user_id)->pessoa;
        }else if(auth()->check()) {
            $p = auth()->user()->pessoa;
        }

        if(empty($p)) return false;

        //update user's preferences
        $preferencias = $p->getPreferencias();
        $preferencias['ativar_agenda'] = $status;
        $p->update(['preferencias' => json_encode($preferencias) ]);
        return true;
    }


    /** 
     * Return auth user's token
     */
    public function getTokenCalendar(User $user = null){
        
        if(empty($user)) 
            $user = auth()->user();
            
        $id = $user->id;
        
        $filename = 'super-secret-folder/user-token'.$id.'.json';
        if(!Storage::disk('local')->exists($filename)) abort(403);
        $token = json_decode(decrypt(Storage::disk('local')->get($filename)), true);

        //validate token
        if($token == null || !array_key_exists('access_token', $token)) {
            return $this->authCalendar();
        }

        return $token;
    }


    /**
     * List Google Calendar events
     */
    public function listEvents($timeMin = null, $timeMax = null)
    {
        try{

            $calendarId = 'primary';

            //set defaults
            if(empty($timeMin)) $timeMin = Carbon::now();
            if(empty($timeMax)) $timeMax = Carbon::now()->addDays(10);

            $timeMin = $this->formatDate($timeMin);
            $timeMax = $this->formatDate($timeMax);

            $optParams = array(
                'maxResults' => 100,
                'orderBy' => 'startTime',
                'singleEvents' => true,
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
            );

            if(empty($this->service)) 
                return null;
            
            $results = $this->service->events->listEvents($calendarId, $optParams);
            $events = $results->getItems();

            return $events;
        }
        catch(Google_Exception | Google_Service_Exception | Throwable | Exception $e){
            if(strpos($e->getMessage(), 'invalid_grant') > 0 && !empty($this->user)){
                Log::info(__CLASS__." User not allowed (403 - listEvents): ".$this->user->id);
                
                //disable user's calendar
                $this->setStatusAgenda(false, $this->user->id);
            }elseif(strpos($e->getMessage(), 'The request is missing a valid API key')){
                Log::info(__CLASS__." The request is missing a valid API key.");
            }else{
                Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
            }
        }


    }


    /**
     * Create event object of Google Calendar
     */
    public function createEvent($dados){

        if(!is_array($dados)) return null;

        //format participants
        $arr_participantes = explode(',', $dados['participantes']);
        $participantes = [];
        foreach($arr_participantes as $p){
            if(filter_var($p, FILTER_VALIDATE_EMAIL)){
                $participantes[] = ['email' => $p];
            }
        }

        //format data
        $data_inicio = $this->formatDate($dados['data_inicio']);
        $data_fim = $this->formatDate($dados['data_fim']);


        $event = new Google_Service_Calendar_Event(array(
            'summary' => $dados['titulo'],
            //'location' => 'A definir',
            'description' => $dados['descricao'],
            'start' => array(
                'dateTime' => $data_inicio,
                'timeZone' => 'America/Sao_Paulo',
                ),
            'end' => array(
                'dateTime' => $data_fim,
                'timeZone' => 'America/Sao_Paulo',
                ),
            'attendees' => $participantes,
            'reminders' => array(
                'useDefault' => FALSE,
                'overrides' => array(
                    array('method' => 'email', 'minutes' => 24 * 60),
                    array('method' => 'popup', 'minutes' => 10),
                    ),
                ),
        ));

        return $event;
    }

    /**
     * Store Google Calendar event
     */
    public function storeEvent(Google_Service_Calendar_Event $event)
    {
        try{

            if(empty($this->service)) 
                return null;
           
            $calendarId = 'primary';
            $event = $this->service->events->insert($calendarId, $event);

            return $event;
        }
        catch(Google_Exception | Throwable | Exception $e){
            Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
        }
    }

    /**
     * Update Google Calendar event
     */
    public function updateEvent(Google_Service_Calendar_Event $event, $eventId)
    {
        try{

            if(empty($this->service)) 
                return null;

            $calendarId = 'primary';
            $event = $this->service->events->update($calendarId, $eventId, $event);

            return $event;
        }
        catch(Google_Exception | Throwable | Exception $e){
            Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
        }
    }

    /**
     * Delete Google Calendar event
     */
    public function deleteEvent($eventId)
    {
        try{
            if(empty($this->service)) 
                return null;
                
            $calendarId = 'primary';
            $this->service->events->delete($calendarId, $eventId);

            return true;
        }
        catch(Google_Exception | Throwable | Exception $e){
            Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
        }
    }


    /**
     * Fix date to Google Datetime format (RFC3339 pattern)
     */
    function formatDate($date)
    {
        if(is_object($date) && get_class($date) == 'Carbon\Carbon')
        {
            //$format = "Y-m-d\TH:i:sP"; //RFC3339
            $format = \DateTime::ATOM;
            return $date->format($format);
        }
        
        return $date;
    }
}
