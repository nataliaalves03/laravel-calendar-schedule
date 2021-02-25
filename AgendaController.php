<?php
//Reference: https://developers.google.com/calendar/quickstart/php

namespace App\Http\Controllers;

use Log;
use App\Agenda;
use Carbon\Carbon;
use Exception;
use App\User;
use App\Pessoa;
use App\Http\Controllers\Api\GoogleCalendarController;

class AgendaController extends Controller{
    
    /**
     * Sincronize Google Calendar to Local Calendar
     */
    public function sincronizarAgenda(){
        $user = auth()->user();
        $this->syncGoogleToAgenda($user);
        
        //redirect to My Account
        return redirect()->route('coach.show');
    }
    
    /**
     * Get local calendar schedules formatted with days and hour intervals
     * It's used to show the calendar at Make your appointment (4You profile)
     */
    public function getAgenda(Pessoa $p, $sincronizar = false){
        try{
            $preferencias = $p->getPreferencias();

            //check if the calendar is active
            if(empty($preferencias) || !array_key_exists('ativar_agenda', $preferencias) || $preferencias['ativar_agenda'] == false) return [];

            $horarios_atendimento = $this->getHorarioAtendimento($p);
            if(empty($horarios_atendimento)) return [];


            //weekly schedule
            $calendar_ini = Carbon::now()->setTimezone('America/Sao_Paulo');//->startOfWeek(0);
            $max_dias = 10;

            if($sincronizar){
                $this->syncGoogleToAgenda($p->usuario);
            }

            $agenda_dias = [];

            //daily and hourly schedules
            for($d = 0; $d < $max_dias; $d++){

                $dt = $calendar_ini->copy()->addDays($d);

                $agenda_dias[$d]['dia'] = $dt;
                $agenda_dias[$d]['horarios'] = [];
                
                //working hours
                $dia_semana = $dt->dayOfWeek;
                $horario_inicio = $horarios_atendimento[$dia_semana][0];
                $horario_fim = $horarios_atendimento[$dia_semana][1];

                //check if has available working hours on this day
                if(!empty($horario_inicio) && !empty($horario_fim)){

                    //hours
                    $horario_inicio = Carbon::createFromFormat('H:i', $horario_inicio);
                    $horario_fim = Carbon::createFromFormat('H:i', $horario_fim);

                    //set today's start time
                    if($dt->isToday()){
                        $agora = Carbon::now();
                        if($agora >= $horario_inicio && $agora < $horario_fim){
                            $horario_inicio->setHour($agora->hour +1);
                        }
                        else if($agora > $horario_fim){
                            //it's not possible to schedule today
                            continue;
                        }
                    }

                    //time intervals
                    $intervalo_horas = $horario_inicio->diffInHours($horario_fim);
                    $horarios_dia = [];

                    //set start time of the day
                    $dt = $calendar_ini->copy()->addDays($d)
                        ->setHour($horario_inicio->hour)
                        ->setMinute($horario_inicio->minute)
                        ->startOfMinute();

                    //intialize all hour intervals as active
                    for($h = 0; $h < $intervalo_horas; $h++){
                        $hora = $dt->copy()->addHours($h);
                        $horarios_dia[] = [$hora, true];
                    }

                    //day calendar
                    $agenda_dia = $p->agendamentos()
                        ->where(function($q) use($dt){
                            return $q->where(function($qq) use($dt){
                                    return $qq->where('data_inicio','>=', $dt->format('Y-m-d').' 00:00:00')
                                        ->where('data_inicio','<', $dt->format('Y-m-d').' 23:59:59');
                                })->orWhere(function($qq) use($dt){
                                    return $qq->where('data_fim','>', $dt->format('Y-m-d').' 00:00:00')
                                        ->where('data_fim','<=', $dt->format('Y-m-d').' 23:59:59');
                                });
                        })->get();
                        

                    $agenda_dias[$d]['eventos'] = $agenda_dia;


                    //check available hour intervals
                    foreach($agenda_dia as $evento){

                        $dt_evento_ini = $evento->data_inicio->setMinute(0)->startOfMinute();
                        $dt_evento_fim = $evento->data_fim->startOfMinute();

                        foreach($horarios_dia as $k => $hora_dia){
                            //if the hour interval is between the event's start and end 
                            //and the event ends after this hour interval
                            if($horarios_dia[$k][0]->between($dt_evento_ini, $dt_evento_fim) 
                                && $dt_evento_fim > $horarios_dia[$k][0]){
                            
                                //set as unavailable
                                $horarios_dia[$k][1] = false;
                            }
                        }
                    }

                    $agenda_dias[$d]['horarios'] = $horarios_dia;
                }
                                
            }

            return $agenda_dias;

        }catch(Throwable | Exception $e){
            Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
        }

    }

    /**
     * Sync Google Calendar to Local Agenda
     */
    public function syncGoogleToAgenda(User $user = null, $calendar_ini = null, $calendar_fim = null)
    {
        try{
            if(empty($user)) $user = auth()->user();

            $dias_sync = 30; //days to sync

            if(empty($calendar_ini)) $calendar_ini = Carbon::now();
            if(empty($calendar_fim)) $calendar_fim = $calendar_ini->copy()->addDays($dias_sync);

            $googleCt = new GoogleCalendarController($user);
            $events = $googleCt->listEvents($calendar_ini, $calendar_fim);

            $eventos_sync = [];

            if(!empty($events)) {
                foreach ($events as $event) {                   

                    $data_ini = $event->getStart()->getDateTime();
                    if (empty($data_ini)) {
                        $data_ini = $event->getStart()->getDate();
                    }

                    $data_fim = $event->getEnd()->getDateTime();
                    if (empty($data_fim)) {
                        $data_fim = $event->getEnd()->getDate();
                    }

                    $format = "Y-m-d\TH:i:sP"; //RFC3339

                    //all day events
                    if(strlen($data_ini) == 10 && Carbon::createFromFormat("Y-m-d", $data_ini)){
                        $data_ini .= "T00:01:00-03:00";
                    }
                    if(strlen($data_fim) == 10 && Carbon::createFromFormat("Y-m-d", $data_fim)){
                        $data_fim .= "T23:59:00-03:00";
                    }

                    $participantes = [];
                    foreach($event->getAttendees() as $atendee){
                        $participantes[] = $atendee->getEmail();
                    }

                    //create or update existing events
                    $evento = Agenda::updateOrCreate([
                        'id_evento_google' => $event->id,
                        'id_pessoa' => $user->id_pessoa,
                    ],[
                        'titulo' => $event->getSummary(),
                        'descricao' => $event->getDescription(),
                        'local' => $event->getLocation(),
                        'participantes' => implode(',', $participantes),
                        'data_inicio' => Carbon::createFromFormat($format, $data_ini),
                        'data_fim' => Carbon::createFromFormat($format, $data_fim),
                        'sync_at' => Carbon::now(),
                    ]);

                    if(empty($evento->origem)){
                        //particular meeting, not created by us
                        $evento->update([
                            'titulo' => null,
                            'descricao' => null,
                            'local' => null,
                        ]);
                    }

                    $eventos_sync[] = $event->id;
                    
                }
            }

            //events deleted from Google Calendar
            $user->pessoa->agendamentos()
                ->whereNotIn('id_evento_google', $eventos_sync)
                ->whereNotNull('sync_at')
                ->delete();


        }catch(Throwable | Exception $e){
            Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
        }

        return true;
    }



    //Sync and create events in Google Calendar that was not sincronized before
    //(eg. auth error when schedule a meeting)
    public function syncAgendaToGoogle(Pessoa $p){

        $googleCt = new GoogleCalendarController($p->usuario);

        //retrive not synced events
        $eventos = $p->agendamentos()->whereNull('sync_at')->where('created_at','>','yesterday')->get();

        foreach($eventos as $evento){

            $dados_evento = [
                'titulo' => $evento->titulo,
                'descricao' => $evento->descricao,
                'data_inicio' => $evento->data_inicio,
                'data_fim' => $evento->data_fim,
                'local' => $evento->local,
                'participantes' => $evento->participantes,
            ];

            //create a Google Calendar's event
            $event_obj = $googleCt->createEvent($dados_evento);
            $google_event = $googleCt->storeEvent($event_obj);

            if(!empty($google_event)){
                //update local calendar with sync timestamps and Google's event ID
                $evento->update([
                    'sync_at' => Carbon::now(),
                    'id_evento_google' => $google_event->id,
                ]);
            }
            
        }
        
        return true;
    }


    public function cronSync(){
        $pessoas = Pessoa::whereNotNull('preferencias')->get();

        foreach($pessoas as $p){
            $preferencias = $p->getPreferencias();

            //check if user's calendar is active
            if(!array_key_exists('ativar_agenda', $preferencias) || $preferencias['ativar_agenda'] !== true ) {
                // echo 'desativar_agenda '.$p->id;
                continue;
            }

            try{
                $this->syncGoogleToAgenda($p->usuario);
            }catch(Exception | Throwable $e){
                Log::info('Fail syncGoogleToAgenda: '.$e->getMessage());
            }

            try{
                $this->syncAgendaToGoogle($p);
            }catch(Exception | Throwable $e){
                Log::info('Fail syncAgendaToGoogle: '.$e->getMessage());
            }
        }
    }


    /**
     * Make an appointment
     */
    public function cadastrarEvento(Pessoa $p, $dados_evento)
    {
        //insert in local calendar
        $agendamento = $p->agendamentos()->create($dados_evento);

        //create event in google calendar
        $googleCt = new GoogleCalendarController($p->usuario);
        $event_obj = $googleCt->createEvent($dados_evento);
        $google_event = $googleCt->storeEvent($event_obj);

        //update local calendar with sync timestamps
        if(!empty($google_event)){
            $agendamento->update([
                'sync_at' => Carbon::now(),
                'id_evento_google' => $google_event->id,
            ]);
        }

        return $agendamento;
    }



    /**
     * Get working hours defined by user's preferences
     */
    public function getHorarioAtendimento(Pessoa $p)
    {
        try{
            $preferencias = $p->getPreferencias();
            
            //validate working hours
            if(empty($preferencias) || !array_key_exists('horario_atendimento', $preferencias) || !is_array($preferencias['horario_atendimento'])) return [];
            
            $horarios = $preferencias['horario_atendimento'];

            $arr = array();
            $dias = ['domingo','segunda','terca','quarta','quinta','sexta','sabado','feriado'];

            if(!empty($horarios)){
                foreach($dias as $dia){
                    $arr[] = explode('-', $horarios['horario_'.$dia]);
                }
            }

            return $arr;

        }catch(Throwable | Exception $e){
            Log::error(__CLASS__ . '/' . __METHOD__ . ': ' . $e);
        }

        return null;
    }

    

 
    
}