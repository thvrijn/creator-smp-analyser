<?php

namespace Database\Seeders;

use App\Models\Player;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Creator SMP 4 players from https://creatorsmp.nl/spelers (October 2026), with their profile photos and Twitch channels.
 * Safe to re-run: existing players are kept, and a photo or Twitch channel is only filled in where it is missing.
 */
class CreatorSmpPlayerSeeder extends Seeder
{
    // The creatorsmp.nl CDN resizes to 256px (~12 KB instead of the ~800 KB originals).
    private const PHOTO_URL = 'https://storage.creatorsmp.nl/cdn-cgi/image/width=256,height=256,fit=scale-down,quality=82,format=auto/players/';

    private const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Player name => [their photo on storage.creatorsmp.nl, their Twitch login (null: no Twitch)]. */
    private const PLAYERS = [
        'Acid' => ['vbuqkuydpHZazAonkWkRystpVkVyXJQNwaZugikWHGXvEzjYGiyfLUZpKimxoiDJ.png', 'acidtwee'],
        'AkaLuuk' => ['GEeUJrnSuLHYaQZiMPkBscVqUNvOQrlzqDjbUHTuBPDlExJoOUxAbQViSzmOImxi.jpg', 'akkaluuk'],
        'Alex Klein' => ['qblrPpnFVsNMoXaAOvjxJxOzPRxHTUAwGmauMXVqWFGGaaTMclJEYwtYJFNqCChv.png', 'alexkleinyt'],
        'alissarella' => ['JuptOdzoMkNUvFfoBMhLLdrmvtTWskndlFjKGnDJZfpnxkviIhaxmXeMdYynFIFB.png', 'alissarella'],
        'AltijdMelvin' => ['rEbMVkRIKNvJVtLWLjphpeURdkdARuXlGUpdtxdSQcGpwIpUqQVufUcOhZwWynoS.png', 'altijdmelvin'],
        'APPELKAAS' => ['IGqONmiKGIdwDUKvHVNQqfxnXcrHmbkQWibCYjwVftIlEafjTYxSIKTSnMenBlib.jpg', 'appelkaas'],
        'AwesomeDad' => ['XuvVOSWaHezyFKsGtxiEGduRwVjlLyAkhNuKuuIeimHoEiIDjqfQkOrZQSlPlhTP.png', 'awesomedad'],
        'AYV3E' => ['wIvcAEvlYzXfCsNCJerHmuvgghrmoNiSeJKjlMzObtMbrIrbAffBpUuPvvWgspsy.jpg', 'ayv3e'],
        'BardoEllens' => ['JugJZaRvAJoEWvMjtCpyfbTiXYWnXyDrrRCRoCudJKRNEtBEQGHCCBKycdFZzvJL.png', 'bardo'],
        'Bassistentje' => ['lvdDBWHPwNLQjBmCIDeGCkHQRQHPJrTzRRwptROShwHFsMeGSBCBQfqizRiEYawQ.jpg', 'bassistentje'],
        'BellieTV' => ['NjDPCPryUwtkefjdfDoBFtjNXDxxaFHvAaBpdeaLJxkXqFPaCmDyuCRtpqqDdcCC.png', 'bellietv'],
        'Bintjeeeh' => ['JHbJBkgTtyhdJFkEkqBuRkzzTsoZNfpMsJWQUmDyrFirGhvMmvqkNXDoTRFjRUNm.png', 'bintjeeeh'],
        'Bokado' => ['TSxtDBgFirPVmxGmyxWdtgVoqjkfFuVCxEVGSwgZXBNuPKrUZVuHwDOyrmyNDwmN.jpg', 'bokado'],
        'Brolsen' => ['byaRYEVRjnTMCyVokrtWqxEtJbzDusEZFYfdRJJmaRnnntcwHFkbknkbBGKQuTQy.png', 'brolsen'],
        'Chatmo' => ['jZLQsqDoMAfwUORrOShQekIEylIvhSMOePqLiCgpJHHgDwmhpHZhJVBgWFXLPzSb.png', 'chatmo'],
        'ClownPierce' => ['WDWuJveuRHVrEgprDSDBpbOoTDvDOjFrcjpCKHIJTWdzRDzOIWvRVZSbeathGHYY.png', 'clownpiercenl'],
        'Dante' => ['LxMpUydqLAKoWmdfnudTHgxhCoLQrvmCEFsDKVirQkyqxJXGLqyMXhpEHqLicjkA.jpg', 'clonnylive'],
        'DavincstyleGames' => ['CPkMvWDUFYvupCJByAKJZxYpJkfMazFRdpNQmVbcWjxgbJGHUYrTKWUsBVWUcVEc.png', 'davincstylegames'],
        'Deem' => ['inVEHsAhWVjBXbazcqCydbQksFgnFmKBevBBREFnkstWsUXUjRTQNpwkfiJoarVk.jpg', 'deem'],
        'Dennus' => ['pGGZFWfurXAJeAEHgJKfKzTKNKeieTeePHKNesaZFCouwsAGUEqfmbBLahskeHbC.png', 'dennusyt'],
        'DionTVG' => ['HqUNinUqNNQuVZvgophdJGXwuucfPDYuyffdYMUXjyNuXzqoJrukqyDNnHYWGysZ.png', 'diontvg'],
        'DonKaaklijn' => ['wECrxufGrCUjubiPssspKCZBCNVwHCjpGFhRWQXowzxqbApTXAjfMPZCiEeJmQjD.png', 'donkaaklijn'],
        'Duncan' => ['pGGZFWfurXAJeAEHgJKfKzTKNKeieTeePHKNesaZFCouwsAGUEqfmbBLahskeHbC.JPG', 'duncan'],
        'eenhoorntamara' => ['dhoHAJIdeqVJmhCyxqreRDCEMKuyacaYfNtcasfqRTOwQXDasUbFaGKfZgmSrnPC.jpg', 'eenhoorntamara'],
        'egbertlive' => ['tXpYXKeDAeNJCzLEFnaxpZDgpiwecrVdCwEazEwiQeNHTtXGpuGTBsmMuijRzUgK.png', 'egbertlive'],
        'Enzo Knol' => ['bKFwaxtNmnLrtNZLGMRnMdRhkKHsjJZoPJGvfmYEZqPzuyERAQQnUGNuoWEmpQCz.png', 'enzoknol'],
        'Ezra' => ['sCvXKdZIQMjIkGlXDYnWFjpPnItgxJgznOWWzXzGcxRzoIjJnjERCHrqZegjeBXX.jpeg', 'ezra'],
        'Faynominaal' => ['MiGGrqMYNngDykZHGwBeuZgtLCbijaxGTfQpQXTzWLzbtniMAzrNiTTuvxuBBEJx.png', 'faynominaal'],
        'Fems' => ['xipLMJoaslBTeBOsdOVHIeuNRouIdLlryBlzNGfcKrbMvCNujWcBnWOqrwPSxlMZ.jpg', 'fems'],
        'GameMeneer' => ['HvpgmbmvxLCKBwDPMppvPWncXAVTMWyMtaBKryvIeMmXQqzIyQssUECgbSHiAhan.jpg', 'gamemeneer'],
        'GeorgeFaralley' => ['nqHlLiyUMIBomdbawdizZLNFKmYsCePuPfhvfBAqUpJrkWYxRJvdwQISsELRVzhg.png', 'georgefaralley'],
        'Gio Latooy' => ['VyajnJKuifBDWFiixJkdXLdDGrCndAhoKhiCdjxLkuibzDPsYVCBvpRDMYvGDHZM.png', 'giolatooylive'],
        'HARM' => ['CEeqyaZyfsmyLQMgVvxkkaFEhumTAppONUgukTnkIREXOXaQCGPDKnFpKxBCZiqr.jpg', 'harm_live'],
        'henRYANand' => ['BpjbfzBKvUgNYmMDfgTHHpaZbyfkcrUedvqpysERxxgPGYMjFfTXxVJfRDEHYxHX.jpg', 'henryanand'],
        'Idris' => ['JgoTcsoisYJxbDuaEwJfwLywbBRxxupXqurzswwvvYnpoKEzpiYajwvjHsDEdGpa.jpg', 'idris'],
        'Igortjuh' => ['hfxZkZhxBYKxpbrGRPuQVKVacdhCVBidwtcUwRitgBGiZcmPKXfohCEYXXyuGwLG.png', 'igortjuh'],
        'ikbenwela' => ['GNibWNYhNJFpOYjJndWcLltPEKGhPbmOjhesGSotKSGvpioVpjybmVpLMbkhRxNV.jpg', 'ikbenwela'],
        'Imkefleur' => ['ZrvzskqHUdGQQWCxrmuyVFvKjPBfnWbtssozroRWaBnYepVvWaNTqceHNzuoxMdr.png', 'imkefleur'],
        'Its_Ankie' => ['qCZofBHnmMCPVjDpjZmLeWxNxPaKgxhECjWuJAKqBUoTRHidnhEMPaQooaAfnbBc.jpg', 'its_ankie'],
        'Izza' => ['qhvXuXseyUCKAJmYNkKsktvjTixhDNsFYMMMTJdwrfpAZKJhrLFAbdseXLrvjUmE.png', 'izza'],
        'jactha' => ['cCnvmbhYKVFKcCwVxvchgZWoebgEswjaAinKusNNrdYpCCwtsmMRtwuLhFYfJjgr.png', 'jactha'],
        'Javieros' => ['sGrarRXUcwFnXncrREeCEuxnJGeoBbWnzBnaBuiQaMyFRbcYrUJThhiXUzeoFDrm.jpg', 'javieros'],
        'Jelly' => ['YRDWBnNVTgbXNwdTAbcmthcxcvEqnEyEgGqtiDaEiWqFrhiXraWNJjyDKfugkRvb.jpg', 'jellyyt'],
        'Jeremy Frieser' => ['tAgiQJQKgJsUGoDsDzKbamQphadmGkoNoPjnbBXmNtQwTDCJxmsaVHGTBEAPXfsP.jpg', 'jeremyfrieser'],
        'Job Dutchtuber' => ['imtPWeeLAVxxfqujZeGzdqXeTmMkDXJacAcDpDNYWDvTcsCUUKRXPdETAZPDFFNx.jpg', 'dutchtuberlive'],
        'JPAIR' => ['TEgHTMIWVCLRwSQYgZhWiruEPxdCAbsDxYgCBNoxRcSCvZRBsLKfBjnzinxZEHWk.jpg', 'jpair'],
        'kantje' => ['ZMUawNUzWNomaVIRKsckabQwwyhtvXMWYywiYKTQawVkfYQFPeNpPcDryWYUDPWm.jpg', 'kantje'],
        'KingKontent' => ['witLdVkHkwzsLkoHicHGptYzYFZqRknCttZthHgYzhQEvEZboBuChBPufQUCwruz.png', 'kingkontent'],
        'Laagvliet' => ['AJOIfcpTdnydyaOaSiINyDaEYwQkHHXYRKmIbowfdEwopPCtMPVKFAdlRtfzoSVn.png', 'laagvliet'],
        'LinkTijger' => ['enaPumWjBbOlYPYxbqpVYSWfyMzqxIgRgxguTDFWNxVYCREAZArTjhwQUQvCsZwl.png', 'linktijger'],
        'Ljoeb' => ['pdsujHqzEdHwugwTefnpQPQyEadhgERMyEvNpdoWCNzqNedVCAvgdXFsJCMcqJTG.png', 'ljoeb'],
        'Loony' => ['NGTmkPViDGgYGZkVghEHmGZBHGmtJznKfnqzBQLKngEmvMQArzciLeuBnXthmBia.png', 'loony_mc'],
        'Lotje' => ['yxqIJeqdIwWohiCFEjOpcYeLghpntGAMrYGjOmrqBovwUTrUBqZWXgcZsioWNeAE.jpg', 'lotje'],
        'MaicoBeukers' => ['UgijvRjbLHxYxyUPoNKcaTVzGZHhDrdBaFpzFGthGujPemCAzeXgCEjLxrdDFwiQ.png', 'maicobeukers'],
        'Manon' => ['jQVJfNANvbKrcieNjtYgNEHLzMwbYzNRTWFXpmmHQUTGuWfhpKDQJCuXYLmnwgbg.jpg', 'manon'],
        'MarnixWesthuis' => ['eeeDKJnLnTlxSwPaVtPctRPsIBojOSkQTkbcUVuHgTwapCZBRyRcDFwiTonEokze.png', 'marnixwesthuis'],
        'MaysieLive' => ['LxNyxifavMLjesKsDvUNkqatVBwtGpTYMEsYRMDKFhMnWiQcZaCpcMTromxHdmaT.jpg', 'maysielive'],
        'Merretje' => ['xfPdfHVezuzjArDbgcNQhsWTthJjQHoCHFtdwAwfsGCyxJLRLLnmEdiyEnoqrBtT.jpeg', 'merretje'],
        'Mick' => ['UFHXlauBXnyopeMuButaZskkMqQLVNykDFJoxaboUFqKGLYJbnfEedoPuEIPwzhx.jpg', null],
        'Mokka' => ['MpBMlWYFyKHoshbWXDjduwigQqZXsCTNdbNgpQTTjpACtKkfHmQcFESmSnsTdytl.png', 'mokka'],
        'Morrog' => ['QHXbwwfPMAKEtNibUAWEAKHaYfRfDoThFxshcnXmaYamygpsUCJzoQpmUAMfLHXg.png', 'morrog'],
        'NatVlees' => ['XZzjMtpQGEQBcOnPxGkHfhTXKCdoYgdQAHsnrQVcUyPwTHpljYYElgkbaZXUtfJU.jpeg', 'natvlees'],
        'NoaNoella' => ['iaKfUrqjKuhZBPKCbBofbpNbAjoqjXFeKVBaidTxBqRbEBmmanohPinhMYHDeutZ.jpeg', 'noanoella'],
        'Noël Dekkers' => ['dwdcgMbBekNzAUeAqZMXKQQkKjgqMAFnrABbAksZgaiXAtbDkUvAtYFgcHRicfDj.jpg', 'noeldekkers'],
        'NoVertigo' => ['BUKniXvngpkugtByLnMYYPJQZvbnuTCCEzkdzCcGVYVEELzmAFyhYHWdQWUXtoQD.png', 'novertigo'],
        'OmaScheetReet' => ['GoDPXCPWczPATewGhMsNFPfMzzQYWxcppkZoWWiHWqYPTMCwYjzpZPxTPahtXpvE.jpeg', 'omascheetreet'],
        'Pangi' => ['XYhmtePHHoGHxpsNnmjHuBVYuRKNPoQRmqYowvpGFTzJBUWytrzrAdTNDGoeCziz.jpg', 'pangi'],
        'papaneus' => ['dJumcGXXtMaAKKmQEWwVtCuFNhYLRjDACBtmtCvwQXubsEoGCaiXsBBYvpfpxXRQ.jpg', 'papaneus'],
        'Paraduze' => ['gvYTqgkFkKVzDCskejrJYzxpbmzJNWHZXVAZvXKWPcoXLFcBvMeuehGmvrBzjJWG.jpeg', 'paraduze'],
        'Pascal' => ['QbPcEZbkgcYqFGiQHTJuJDzAUdpJJEyknmzquiMKFLhKUwaQkkQvXachmogymJDV.jpg', 'scherpenkate'],
        'Puddingb00m' => ['RzXwRCwynKCCfKxiSSuNRYznbTpMjFhswfymaSKSGzYxNQnfzvQllCVjAILxElWG.png', 'puddingb00m'],
        'PuqLive' => ['ilxHnwlobzVdgeVumlGfGOFFfDdkxpUeZVAGRQQEDLOUeTpdSqWmqPoBfjIVGfSy.png', 'puqlive'],
        'Puxque' => ['YzWytOMwudLKQrMFSBwbgBtqobNePWmOOPdArfGRmATFqlbCGIGpdYljJiYvMDKH.png', 'puxque'],
        'Qucee' => ['VOgGfvZXlobbJidahnTkmHCdCvbrdLadzOrpVRDbfuybZzmuEfrBFkwsZMWyAUYc.jpeg', 'qucee'],
        'QuintenVms' => ['raAxPnzMibVcpAbjmdDYDTwFvzVKDaFrKLeTyDgHjVohcxiPKHLXEiRZdgLFtbbz.jpg', 'quintenvms'],
        'Roedie' => ['TpzuiyXGKDyVEAQXJjYtVGzCpDxBUXBxiPcKLZyqhqXLNxADhnjDNCNLvcePcNCY.png', 'roedie'],
        'Ronald' => ['rTeTijDQDasEwSeMYclIXhPvLiCKfOvnCrlMLPJCYDAuVrGygIizpSpIQakdFAoX.png', 'ronald'],
        'Royalistiq' => ['xupoauRTbqsnyucDmjypxFayHcTsxgkJJBojWUuVQPejassPpNGfovLsoyXxhoeG.jpeg', 'royalistiq'],
        'SanDramatis' => ['dIihYnVkNrGaikXRQiMBokpHgbQvVrUexqCDEhyNgGktHZtpBejstGBMTbltPJyI.jpeg', 'sandramatis'],
        'Santino_YT' => ['eBqZBKRyNLbWMpnJKenLWmmbLxreHpxZRsCjffALuWPZmbNTnGGHTkAeRMQUmiBf.jpg', 'santino0_yt'],
        'sejecem' => ['MsCiRXsyhntJRpyaeUbYifqHXxbbmgbukZHQpeWJHmsXrWTkNBPrkTxPQibVjALQ.png', 'sejecem'],
        'Shandrea' => ['oEkbegflVrmcjaOOisePpPmcNEhJNvcitsictTeVnbBUcMSUBkYJxSkzrpKQgyBb.jpg', 'shandrea'],
        'Shappo0' => ['nPuQJyqPGDdCIWMeGrdfURMgXcIxHgTSegNemyUpSdsZssmoFzAhluQnveAaIrSI.png', 'shappo0'],
        'SlushieVRC' => ['vMNUbMRhAGuKZoBtFZfZUyZQiqhXYNJdRUqILSSloXEqrBSEHqlCAExwIOdeQUHw.png', 'slushievrc'],
        'spacingunicorn' => ['LohkeEBGdfjfsTWqkeNiXRgDMYuJGNDABVYRFEdkPsjZHkPrXgMTKpsfmGQgNNaw.jpg', 'spacingunicorn'],
        'StreamwithLien' => ['gpZqdTCzasbzvWfCAodNpBbhXkCsBwbiCMEWozPdxGdWBvipgvUVMbzRVjdYDARB.jpeg', 'streamwithlien'],
        'Vince_STICK' => ['UdJrBreYximGLhAFKypUpzhVmTTFdUwXBdHZkJTBXBECyKCXrTiTsHjcHNqNkFwq.jpg', 'vince_stick'],
        'Weswoes' => ['makADXMybkhzbcmqamqHJZqgLqJtJKGAqeTFzXmUrrDukNgwJAfTAEGPKNPcXgHH.png', 'weswoes'],
        'Wolfeei' => ['cECnXfiLAHRvcfMWyxLNALmaPFixwFgCBXLqbUsJLrrwDzJHcLGfXLGeFDNTkhdE.png', 'wolfeei'],
        'Yarasky' => ['seddVjimBFKPCYPHgQemzcZjVePccekGxdDNmuRmmDDVJtuTHWAZgLRvxedbzXCv.png', 'yaraskygaming'],
    ];

    public function run(): void
    {
        foreach (self::PLAYERS as $name => [$photo, $twitchLogin]) {
            $player = Player::firstOrCreate(['name' => $name]);
            $player->twitch_login ??= $twitchLogin;
            $player->photo_path ??= $this->downloadPhoto($name, $photo);
            $player->save();
        }
    }

    // A player without a photo is still added; a later run tries the photo again.
    private function downloadPhoto(string $name, string $photo): ?string
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 500, fn (Throwable $exception) => $exception instanceof ConnectionException)
                ->get(self::PHOTO_URL.$photo);
        } catch (Throwable $exception) {
            $this->command?->warn("No photo for {$name}: {$exception->getMessage()}");

            return null;
        }

        $extension = self::PHOTO_TYPES[strtok((string) $response->header('Content-Type'), ';')] ?? null;
        if ($extension === null) {
            $this->command?->warn("No photo for {$name}: unexpected type {$response->header('Content-Type')}");

            return null;
        }

        $path = 'players/'.Str::random(40).'.'.$extension;
        Storage::put($path, $response->body());

        return $path;
    }
}
