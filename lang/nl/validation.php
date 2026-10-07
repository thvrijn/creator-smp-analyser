<?php

// Only the rules this app uses; any other rule falls back to Laravel's English message.
return [
    'after_or_equal' => ':Attribute moet gelijk aan of later dan :date zijn.',
    'boolean' => ':Attribute moet waar of onwaar zijn.',
    'confirmed' => 'De bevestiging van :attribute komt niet overeen.',
    'current_password' => 'Het wachtwoord is onjuist.',
    'date' => ':Attribute moet een geldige datum zijn.',
    'different' => ':Attribute moet anders zijn dan :other.',
    'exists' => 'De gekozen :attribute bestaat niet.',
    'file' => ':Attribute moet een bestand zijn.',
    'gt' => [
        'numeric' => ':Attribute moet groter zijn dan :value.',
    ],
    'image' => ':Attribute moet een afbeelding zijn.',
    'integer' => ':Attribute moet een geheel getal zijn.',
    'max' => [
        'file' => ':Attribute mag niet groter zijn dan :max kilobytes.',
        'string' => ':Attribute mag niet meer dan :max tekens bevatten.',
    ],
    'min' => [
        'numeric' => ':Attribute moet minstens :min zijn.',
        'string' => ':Attribute moet minstens :min tekens bevatten.',
    ],
    'mimes' => ':Attribute moet een bestand zijn van het type: :values.',
    'mimetypes' => ':Attribute moet een bestand zijn van het type: :values.',
    'regex' => ':Attribute heeft een ongeldig formaat.',
    'numeric' => ':Attribute moet een getal zijn.',
    'required' => ':Attribute is verplicht.',
    'string' => ':Attribute moet tekst zijn.',
    'unique' => 'Deze :attribute is al in gebruik.',
    'uploaded' => ':Attribute kon niet worden geüpload.',

    'attributes' => [
        'name' => 'naam',
        'photo' => 'foto',
        'remove_photo' => 'foto verwijderen',
        'twitch_login' => 'Twitch-kanaal',
        'player_id' => 'speler',
        'title' => 'titel',
        'started_at' => 'starttijd',
        'ended_at' => 'eindtijd',
        'source' => 'bron',
        'video' => 'video',
        'start_seconds' => 'begin',
        'end_seconds' => 'einde',
        'event_id' => 'event',
        'current_password' => 'huidig wachtwoord',
        'password' => 'wachtwoord',
        'username' => 'gebruikersnaam',
    ],
];
