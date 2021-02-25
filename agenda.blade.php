<? 
//dd($agenda); ?>

@if($pessoa->canAgenda() && !empty($agenda))

<div class="col-6">

<div class=" text-center mb-4 p-3 bg-secondary">
    <h3 class="title-bold text-dark-pink fs-16">@lang('Agende uma sessão')</h3>
    <p class="mb-0">@lang('Qual é o melhor horário para você?')</p>
</div>

@if(!empty($agenda))
    <div id="agenda-4you" class="owl-carousel">
    @foreach($agenda as $dia)
        <div class="dia-agenda item">
            <p class="dia-titulo">
                @if($dia['dia']->isToday())
                    @lang('Hoje')
                @elseif($dia['dia']->isTomorrow())
                    @lang('Amanhã')
                @else
                    {{ __($dia['dia']->format('l')) }}
                @endif
            </p>
            <p class="dia-data">{{ $dia['dia']->format('d/m') }}</p>
            <ul class="horarios-agenda">
                @forelse($dia['horarios'] as $horario)
                <li class="horario-agenda list-unstyled">
                    @if($horario[1])
                        <a class="btn btn-warning btn-agendar-perfil" data-dia="{{ $dia['dia']->format('d/m/Y') }}" data-hora="{{ $horario[0]->format('H:i') }}"  data-dia-semana="{{ $dia['dia']->isToday() ? __('Hoje') : ($dia['dia']->isTomorrow() ? __('Amanhã') : __($dia['dia']->format('l')) ) }}" 
                            data-toggle="modal" data-target="#modal-agendar-perfil" >{{ $horario[0]->format('H:i') }}</a>
                    @else
                        <p class="bg-secondary py-1">{{ $horario[0]->format('H:i') }}</p>
                    @endif
                </li>
                @empty
                <p class="small text-dark">@lang('Nenhum horário disponível').</p>
                @endforelse
            </ul>
        </div>
    @endforeach
    </div>
@else
    <div id="agenda-4you" class="text-center bg-lighter py-5">
        <p><i class="ti-calendar"></i> @lang('Nenhum horário disponível').</p> 
        <a href="#" data-toggle="modal" data-target="#modal-contato-perfil" class="btn btn-custom mt-3">@lang('Envie uma mensagem')</a>
    </div>
@endif


</div>


@endif
