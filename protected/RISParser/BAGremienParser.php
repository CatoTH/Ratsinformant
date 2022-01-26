<?php

class BAGremienParser extends RISParser
{
    private static $MAX_OFFSET     = 350;
    public static  $WAHLPERIODE_ID = 5666213;

    public function parse($gremien_id, $wahlperiode_id = 0): ?Gremium
    {
        $wahlperiode_id = IntVal($wahlperiode_id > 0 ? $wahlperiode_id : static::$WAHLPERIODE_ID);
        $gremien_id     = IntVal($gremien_id);
        if (SITE_CALL_MODE != "cron") echo "- Gremium $gremien_id\n";

        $html_details = RISTools::load_file(RIS_BA_BASE_URL . "ba_gremien_details.jsp?Id=" . $gremien_id . "&Wahlperiode=" . $wahlperiode_id);

        $daten                         = new Gremium();
        $daten->id                     = $gremien_id;
        $daten->datum_letzte_aenderung = new CDbExpression('NOW()');
        $daten->referat                = "";

        if (preg_match("/introheadline\">([^>]+)<\/h3/siU", $html_details, $matches)) $daten->name = html_entity_decode(trim($matches[1]), ENT_COMPAT, "UTF-8");
        if (preg_match("/<a href=\"ba_bezirksausschuesse_details[^>]+>(?<ba>[0-9]+ )/siU", $html_details, $matches)) $daten->ba_nr = html_entity_decode(trim($matches["ba"]), ENT_COMPAT, "UTF-8");
        if (preg_match("/rzel:.*detail_div\">([^>]*)<\//siU", $html_details, $matches)) $daten->kuerzel = html_entity_decode(trim($matches[1]), ENT_COMPAT, "UTF-8");
        if (preg_match("/Gremiumtyp:.*detail_div\">([^>]*)<\//siU", $html_details, $matches)) $daten->gremientyp = html_entity_decode($matches[1], ENT_COMPAT, "UTF-8");

        $aenderungen = "";

        /** @var Gremium $alter_eintrag */
        $alter_eintrag = Gremium::model()->findByPk($gremien_id);
        if ($alter_eintrag) {
            $changed = false;
            if ($alter_eintrag->name != $daten->name) $aenderungen .= "Name: " . $alter_eintrag->name . " => " . $daten->name . "\n";
            if ($alter_eintrag->ba_nr != $daten->ba_nr) $aenderungen .= "BA: " . $alter_eintrag->ba_nr . " => " . $daten->ba_nr . "\n";
            if ($alter_eintrag->kuerzel != $daten->kuerzel) $aenderungen .= "Kürzel: " . $alter_eintrag->kuerzel . " => " . $daten->kuerzel . "\n";
            if ($alter_eintrag->gremientyp != $daten->gremientyp) $aenderungen .= "Gremientyp: " . $alter_eintrag->gremientyp . " => " . $daten->gremientyp . "\n";
            if ($aenderungen != "") $changed = true;
        } else {
            $aenderungen = "Neu angelegt\n";
            $changed     = true;
        }

        if ($changed) {
            if ($alter_eintrag) {
                $alter_eintrag->copyToHistory();
                $alter_eintrag->setAttributes($daten->getAttributes());
                if (!$alter_eintrag->save()) {
                    echo "Gremium 3";
                    var_dump($alter_eintrag->getErrors());
                    die("Fehler");
                }
                $daten = $alter_eintrag;
            } else {
                if (!$daten->save()) {
                    echo "Gremium 4";
                    var_dump($daten->getErrors());
                    die("Fehler");
                }
            }
        }

        /** @var StadtraetInGremium[] $mitglieder_pre */
        $mitglieder_pre = [];
        if ($alter_eintrag) {
            foreach ($alter_eintrag->mitgliedschaften as $mitgliedschaft) {
                $mitglieder_pre[$mitgliedschaft->stadtraetIn_id . ':' . $mitgliedschaft->datum_von . ':' . trim($mitgliedschaft->funktion)] = $mitgliedschaft;
            }
        }

        $mitglieder_post = [];
        preg_match_all("/ergebnistab_tr.*<\/tr/siU", $html_details, $matches);
        foreach ($matches[0] as $str) {
            preg_match("/<a[^>]*Id=(?<id>[0-9]+)&[^>]*>(?<name>[^<]*)<\/a>.*<td[^>]*>(?<partei>[^<]*)<\/td.*<td[^>]*>(?<datum>[^<]*)<\/td.*<td[^>]*>(?<funktion>[^<]*)<\/td/siU", $str, $match2);
            if ($match2) {
                /** @var StadtraetIn $stadtraetIn */
                $stadtraetIn = StadtraetIn::model()->findByPk($match2["id"]);
                if (!$stadtraetIn) {
                    $par = new BAMitgliederParser();
                    $kuerzel = preg_replace("/^ua ?/siu", "", $daten->kuerzel);
                    $par->parse(IntVal($kuerzel));

                    $stadtraetIn = StadtraetIn::model()->findByPk($match2["id"]);
                    if (!$stadtraetIn) {
                        $name        = trim(str_replace(["&nbsp;", "Herr", "Frau"], [" ", " ", " "], $match2["name"]));
                        $stadtraetIn = StadtraetIn::model()->findByAttributes(["name" => $name]);
                        if (!$stadtraetIn) {
                            RISTools::report_ris_parser_error("BA-Gremium nicht zuordbar", "Gremium: $gremien_id\nMitglieds-ID: " . $match2["id"]);
                            return null;
                        }
                    }
                }

                $datum     = trim(str_ireplace("von ", "", $match2["datum"]));
                $datum     = str_replace("seit ", "", $datum);
                $datum     = explode(" bis ", $datum);
                $x         = explode(".", $datum[0]);
                $datum_von = $x[2] . "-" . $x[1] . "-" . $x[0];
                if (count($datum) == 2) {
                    $x         = explode(".", $datum[1]);
                    $datum_bis = $x[2] . "-" . $x[1] . "-" . $x[0];
                } else {
                    $datum_bis = null;
                }
                $funktion = trim($match2["funktion"]);

                try {
                    if (isset($mitglieder_pre[$stadtraetIn->id . ':' . $datum_von . ':' . $funktion])) {
                        $mitgliedschaft = $mitglieder_pre[$stadtraetIn->id . ':' . $datum_von . ':' . $funktion];
                        if ($mitgliedschaft->datum_bis != $datum_bis) {
                            $mitgliedschaft->funktion  = $funktion;
                            $aenderungen               .= "Mitgliedschaft von " . $mitgliedschaft->stadtraetIn->name . ": ";
                            $aenderungen               .= $mitgliedschaft->datum_von . "/" . $mitgliedschaft->datum_bis . " => ";
                            $aenderungen               .= $datum_von . "/" . $datum_bis . "\n";
                            $mitgliedschaft->datum_bis = $datum_bis;
                            $mitgliedschaft->save();
                        }
                    } else {
                        $mitgliedschaft                 = new StadtraetInGremium();
                        $mitgliedschaft->datum_von      = $datum_von;
                        $mitgliedschaft->datum_bis      = $datum_bis;
                        $mitgliedschaft->funktion       = trim($match2["funktion"]);
                        $mitgliedschaft->gremium_id     = $gremien_id;
                        $mitgliedschaft->stadtraetIn_id = $stadtraetIn->id;
                        $mitgliedschaft->save();
                        $mitgliedschaft->refresh();;
                        $aenderungen .= "Neues Mitglied: " . $mitgliedschaft->stadtraetIn->name . " ($funktion)\n";
                    }

                    $mitglieder_post[$stadtraetIn->id . ':' . $datum_von . ':' . $funktion] = $mitgliedschaft;
                } catch (Exception $e) {
                    $str = "Gremium: $gremien_id\nStadträt*in: " . $stadtraetIn->id . "\nFunktion: $funktion\n" . $e->getMessage();
                    RISTools::send_email(Yii::app()->params["adminEmail"], "BAGremienParser Inkonsistenz", $str, null, "system");
                }
            }
        }

        foreach ($mitglieder_pre as $strIn_id => $mitgliedschaft_pre) if (!isset($mitglieder_post[$strIn_id])) {
            $aenderungen .= "Mitglied nicht mehr dabei: " . $strIn_id . " - " . $mitgliedschaft_pre->stadtraetIn->getName() . "\n";
            $mitgliedschaft_pre->delete();
        }


        if ($aenderungen != "") {
            echo $aenderungen . "\n";

            $aend              = new RISAenderung();
            $aend->ris_id      = $daten->id;
            $aend->ba_nr       = null;
            $aend->typ         = RISAenderung::TYP_BA_GREMIUM;
            $aend->datum       = new CDbExpression("NOW()");
            $aend->aenderungen = $aenderungen;
            $aend->save();
        }

        return $daten;
    }

    public function parseSeite(int $seite, int $first): array
    {
        if (SITE_CALL_MODE != "cron") echo "BA-Gremien Seite $seite\n";
        $text = RISTools::load_file(RIS_BA_BASE_URL . "ba_gremien.jsp?selWahlperiode=" . static::$WAHLPERIODE_ID . "&Trf=n&Start=$seite");

        $txt = explode("<!-- tabellenkopf -->", $text);
        $txt = explode("<div class=\"ergebnisfuss\">", $txt[1]);
        preg_match_all("/ba_gremien_details\.jsp\?Id=(?<id>[0-9]+)[\"'& ]/siU", $txt[0], $matches);
        if ($first && count($matches[1]) > 0) {
            RISTools::report_ris_parser_error(
                "BA-Gremien VOLL",
                "Erste Seite voll: $seite (" . RIS_BA_BASE_URL . "ba_gremien.jsp?selWahlperiode=" . static::$WAHLPERIODE_ID . "&Trf=n&Start=$seite)"
            );
        }
        for ($i = count($matches[1]) - 1; $i >= 0; $i--) $this->parse($matches[1][$i], static::$WAHLPERIODE_ID);
        return $matches[1];
    }

    public function parseAll(): void
    {
        $anz   = BAGremienParser::$MAX_OFFSET;
        $first = true;
        for ($i = $anz; $i >= 0; $i -= 10) {
            if (SITE_CALL_MODE != "cron") echo ($anz - $i) . " / $anz\n";
            $this->parseSeite($i, $first);
            $first = false;
        }
    }

    public function parseUpdate(): void
    {
        $this->parseAll();
    }

    public function parseQuickUpdate(): void
    {

    }
}
