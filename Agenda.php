<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agenda extends Model
{
    use SoftDeletes;

    protected $table = 'agenda';

    protected $fillable = [
        'id_pessoa', 'id_evento_google', 'data_inicio', 'data_fim', 'titulo', 'descricao', 
        'local', 'participantes', 'sync_at','origem'
    ];

    protected $dates = ['deleted_at', 'data_inicio', 'data_fim', 'sync_at'];

    public function pessoa()
    {
        return $this->belongsTo('App\Pessoa', 'id_pessoa');
    }

}
