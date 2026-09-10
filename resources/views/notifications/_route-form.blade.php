{{--
    Ein Ziel für eine Meldungsart anlegen. Zwei Einsätze:
      - unten auf der Seite: Dropdown mit den Meldungsarten, die noch KEINE Route haben ($auswahl),
      - je Meldungsart in der Liste („weiteres Ziel anlegen"): Meldungsart steht fest ($fest).
    $knopf = Beschriftung des Absende-Knopfs.
--}}
@props(['auswahl' => [], 'fest' => null, 'knopf' => 'Route anlegen'])

<form method="POST" action="{{ route('module.ekkon.notifications.route.store') }}"
      x-data="{ typ: 'mail', mailZiel: 'admins' }"
      class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    @csrf
    @if ($fest !== null)
        <input type="hidden" name="meldungsart" value="{{ $fest }}">
    @else
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Meldungsart</label>
            <select name="meldungsart" class="w-full rounded-md border-gray-300 text-sm">
                @foreach ($auswahl as $art => $klartext)
                    <option value="{{ $art }}" @selected(old('meldungsart') === $art)>{{ $klartext }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Typ</label>
        <select name="typ" x-model="typ" class="w-full rounded-md border-gray-300 text-sm">
            <option value="mail">Mail</option>
            <option value="teams">Teams</option>
        </select>
    </div>
    <div x-show="typ === 'mail'" x-cloak>
        <label class="block text-xs font-medium text-gray-600 mb-1">Mail an</label>
        <select name="mail_ziel" x-model="mailZiel" class="w-full rounded-md border-gray-300 text-sm">
            <option value="admins">alle System-Admins</option>
            <option value="benutzer">bestimmten Administrator</option>
            <option value="adresse">feste Adresse</option>
        </select>
    </div>
    <div x-show="typ === 'mail' && mailZiel === 'benutzer'" x-cloak>
        <label class="block text-xs font-medium text-gray-600 mb-1">Administrator</label>
        <select name="mail_user_id" class="w-full rounded-md border-gray-300 text-sm">
            <option value="">– bitte wählen –</option>
            @foreach ($admins as $admin)
                <option value="{{ $admin->id }}">{{ $admin->name }} ({{ $admin->email }})</option>
            @endforeach
        </select>
    </div>
    <div x-show="typ === 'mail' && mailZiel === 'adresse'" x-cloak>
        <label class="block text-xs font-medium text-gray-600 mb-1">Adresse</label>
        <input name="mail_empfaenger" type="email"
               class="w-full rounded-md border-gray-300 text-sm" placeholder="name@firma.de">
    </div>
    <div x-show="typ === 'teams'" x-cloak>
        <label class="block text-xs font-medium text-gray-600 mb-1">Channel</label>
        <select name="teams_channel_id" class="w-full rounded-md border-gray-300 text-sm">
            <option value="">– bitte wählen –</option>
            @foreach ($channels as $channel)
                <option value="{{ $channel->id }}">{{ $channel->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="{{ $fest !== null ? 'md:col-span-4 flex gap-3 items-center' : 'md:col-span-4' }}">
        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            {{ $knopf }}
        </button>
        @if ($fest !== null)
            <button type="button" @click="zielFuer = null" class="text-sm text-gray-600 hover:underline">abbrechen</button>
        @endif
    </div>
</form>
