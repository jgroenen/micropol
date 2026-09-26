# Analyse: groepen van deelnemers

Per gesprek zoekt de analyse groepen deelnemers die stellingen op een vergelijkbare manier beantwoorden, losjes naar de aanpak van [Polis](https://pol.is). De uitkomst is te zien op de matrixpagina van een gesprek (`#/gesprekken/<id>/matrix`) en in het tabblad „Groepen bekijken”. Hij komt van de math server: `GET /analyse?api=<url van de api>&gesprek_id=<id>`. De math server rekent op de standaardexport van de API (`GET /export`), zie [de README](../README.md#math-server).

Dit document legt uit hoe de analyse werkt, welke keuzes erin zitten en waarom, en waar je iets aanpast.

## In het kort

1. De antwoorden worden een matrix: deelnemers als rijen, stellingen als kolommen.
2. **PCA** brengt die matrix terug tot de 3 richtingen waarin deelnemers het meest van elkaar verschillen.
3. **K-means** deelt de deelnemers op die 3 richtingen in groepen in. Het aantal groepen K is het kleinste aantal waarbij **elke groep iets kenmerkends heeft**.
4. Het resultaat (het *model*) wordt per gesprek opgeslagen en hooguit elke **6 uur** opnieuw berekend.
5. Met dat model worden deelnemers **live** geplaatst: op de server voor iedereen, en in de browser voor de huidige gebruiker.
6. De plot toont de eerste 2 richtingen. De pagina laat per groep zien wat kenmerkend is, en welke stellingen alle groepen delen.

## Waar staat wat

| Bestand | Wat |
|---|---|
| `math/lib/Analyse.php` | Het rekenwerk: `maakModel()` (duur) en `plaats()` (goedkoop) |
| `math/lib/AnalyseModel.php` | Opslaan, laden en verversen van het model |
| `math/lib/Export.php` | Ophalen en controleren van de export van de API |
| `math/handlers/AnalyseHandler.php` | `GET /analyse`: haalt de export en het model op en plaatst alle deelnemers |
| `math/data/analyse/<bron>/<gesprek_id>/` | Het opgeslagen model: `pca.json` en `kmeans.json`; `<bron>` staat voor de API |
| `api/handlers/ExportHandler.php` | `GET /export` op de API: de antwoorden in het standaardformaat |
| `app/js/analyse.js` | `plaats()` in de browser (zelfde berekening als in PHP), de plot, en kenmerkend, eensgezind en consensus |
| `app/js/matrix.js` | De matrixpagina |
| `app/js/gesprek.js` | "Met je huidige antwoorden hoor je bij groep …" tijdens het beantwoorden |

## Stap voor stap

### 1. Antwoorden als getallen

| Antwoord | Waarde |
|---|---|
| eens | +1 |
| neutraal | 0 |
| oneens | −1 |
| niet beantwoord | het gemiddelde van die stelling |

Een ontbrekend antwoord krijgt het gemiddelde, zodat het de positie van de deelnemer niet beïnvloedt: na het centreren telt het als 0. Heeft iemand een stelling vaker beantwoord, dan telt het laatste antwoord. Het antwoordenbestand wordt alleen aangevuld, nooit gewijzigd.

Alleen deelnemers met minstens **7 antwoorden** (of alle stellingen, als het gesprek er minder heeft) tellen mee voor het model. Met minder is iemands positie te onzeker. Zij verschijnen ook niet in de plot, maar zodra ze genoeg hebben geantwoord wel, ook zonder dat het model opnieuw berekend wordt.

### 2. PCA: de belangrijkste richtingen

Van de gecentreerde matrix wordt de covariantiematrix berekend (stellingen × stellingen). De drie grootste eigenvectoren worden één voor één gevonden met *power iteration*, waarbij de gevonden richting na elke stap uit de matrix wordt gehaald (deflatie). Er zijn geen externe libraries nodig.

Om hetzelfde resultaat bij dezelfde data te garanderen:
- de startvector staat vast;
- het teken van elke richting staat vast: het grootste element is positief.

Een deelnemer wordt op elke richting geprojecteerd en daarna opgeschaald met `√(aantal stellingen / aantal beantwoord)`. Zonder die schaling belanden mensen met weinig antwoorden allemaal in het midden. Polis doet hetzelfde.

### 3. K-means: groepen

K-means draait op de posities in **3 dimensies**, niet alleen op de 2 van de plot. De startpunten worden deterministisch gekozen: eerst het punt dat het verst van het gemiddelde ligt, daarna telkens het punt dat het verst van alle gekozen punten ligt.

**Hoe K gekozen wordt.** Van K = 3, 4, 5 (niet meer dan het aantal deelnemers − 1) wordt de **kleinste K gekozen waarbij elke groep minstens één kenmerkende stelling heeft** (zie hieronder). Lukt dat bij geen enkele K, dan wordt de K gekozen met de beste *silhouette-score*: hoe goed de groepen van elkaar gescheiden liggen. Welke van de twee het werd, staat in `k_gekozen_op` (`"kenmerkend"` of `"silhouette"`). Liggen alle deelnemers op hetzelfde punt, dan zijn er geen groepen.

Groep A is de grootste groep, B de volgende, enzovoort, behalve als er een vorig model is (zie stap 4).

#### Waarom zo, en niet alleen de silhouette in 2D?

Oorspronkelijk werd K gekozen op de silhouette-score in 2D. In een simulatie van het gesprek "Cultuur in de klas" gaat dat mis. Die simulatie had 1000 deelnemers en 200 stellingen, met vier verborgen typen: *cultuur is onzin*, *cultuur is duur*, *cultuur is het waardevolste* en *cultuur van nu*.

| Aanpak | K | Zuiverheid* | Kenmerkende stellingen per groep |
|---|---|---|---|
| Silhouette, 2D (oud) | 3 | 69% | 31, 48, **0** |
| K = 4, 2D | 4 | 79% | 47, 65, 6, 1 |
| **Kenmerkend, 3D (nu)** | **4** | **88%** | 49, 64, 11, 6 |

\* het deel van de deelnemers dat in de groep van hun eigen type zit

Met de oude aanpak vielen "duur" en "van nu" samen in één groep, en die groep had niets kenmerkends. Twee dingen gingen daarbij mis:
- **De silhouette kijkt alleen naar afstanden in de plot,** niet naar wat een groep inhoudelijk betekent. Een groep zonder enige kenmerkende stelling is een teken dat er eigenlijk twee groepen in zitten.
- **De twee typen verschilden vooral op een derde richting** (kosten tegenover klassiek of modern), die in 2D wegvalt.

**Gevolg:** omdat de groepen op 3 richtingen bepaald worden en de plot er 2 toont, kunnen groepen in de plot wat door elkaar lopen. De pagina meldt dat.

In een eerdere simulatie (152 deelnemers, 3 stromingen, 70% consistent) haalt geen enkele K voor elke groep 90%. Daar valt de keuze terug op de silhouette, en blijft het resultaat hetzelfde als met de oude aanpak.

### 4. Het model opslaan en verversen

Per API en gesprek staat het model in `math/data/analyse/<bron>/<gesprek_id>/`:

| Bestand | Inhoud |
|---|---|
| `pca.json` | `stellingen` (volgorde van de kolommen), `gemiddelden`, `componenten` (3 vectoren), `verklaarde_variantie`, `min_antwoorden`, `berekend` |
| `kmeans.json` | `k`, `centra` (middelpunt per groep, in 3D), `gekozen_op`, `berekend` |

Het model wordt opnieuw berekend als een bestand ontbreekt of ouder is dan **6 uur** (`AnalyseModel::MAX_LEEFTIJD`). Dat gebeurt bij het eerste verzoek daarna, niet op een vaste klok. Met `&herbereken=1` forceer je het.

- **Eén tegelijk:** een lockbestand (`.lock`) zorgt dat maar één verzoek tegelijk herberekent. Andere verzoeken wachten en gebruiken daarna het nieuwe model.
- **Veilig wegschrijven:** bestanden worden eerst naar een tijdelijk bestand geschreven en dan hernoemd, zodat niemand een half model leest.
- **Stabiele groepsletters:** bij een herberekening worden de deelnemers ook met het *oude* model geplaatst. Een nieuwe groep krijgt de letter van de oude groep waar de meeste van zijn leden vandaan komen, zodat de letters niet verspringen. In een test waarin groep C van 30 naar 84 leden groeide, hield 147 van de 150 bestaande deelnemers dezelfde letter.

Het model is alleen afgeleide data. Je kunt de map `math/data/analyse/` altijd weggooien: bij het volgende verzoek wordt hij opnieuw opgebouwd.

### 5. Live plaatsen

Met het opgeslagen model is een deelnemer plaatsen goedkoop: per richting een inproduct over de stellingen, dan het dichtstbijzijnde groepsmiddelpunt. Dat is dezelfde regel die K-means gebruikt.

- **Op de math server** haalt `GET /analyse` bij elk verzoek de export op en plaatst alle deelnemers opnieuw. Nieuwe antwoorden verplaatsen deelnemers dus direct. De richtingen en middelpunten veranderen pas bij een herberekening.
- **In de browser** doet `plaats()` in `app/js/analyse.js` exact dezelfde berekening. Het model zit in de response, onder `model`, en de browser kent de eigen antwoorden. Zo ziet de gebruiker zijn eigen punt ("jij") en groep, ook terwijl hij antwoordt, zonder extra verzoek.

Houd `plaats()` in PHP en JavaScript gelijk. Na een wijziging kun je controleren of beide dezelfde groep en positie geven: de positie uit de API is afgerond op 4 decimalen.

**Beperking:** een stelling die na de laatste berekening is toegevoegd, zit nog niet in het model. Antwoorden daarop tellen pas mee voor iemands positie na de volgende herberekening.

## Wat de pagina over groepen zegt

Deze berekeningen gebeuren in de browser (`app/js/analyse.js`), op basis van de tellingen per groep en stelling in `groepen[].stellingen`. Percentages zijn steeds het deel van de groepsleden **die de stelling beantwoordden**; neutraal telt mee in het totaal.

| Begrip | Regel |
|---|---|
| **Kenmerkend** | ≥ 90% van de groep geeft hetzelfde antwoord (eens of oneens), en de andere deelnemers samen minstens 30 procentpunt minder |
| **Verder eens of oneens** | ≥ 70% van de groep geeft hetzelfde antwoord (ingeklapt op de kaart) |
| **Consensus** | in *elke* groep ≥ 70% hetzelfde antwoord |

**Genoeg antwoorden:** er moeten minstens 3 antwoorden zijn, van minstens 20% van de betreffende deelnemers. Zo wordt "2 van de 2" geen 100%.

De regel voor **kenmerkend** wordt ook in PHP gebruikt om K te kiezen (`Analyse::elkeGroepKenmerkend`). De drempels staan dus op twee plekken en moeten gelijk blijven: `KENMERKEND_*` in `math/lib/Analyse.php` en in `app/js/analyse.js`. In PHP gebeurt dat op het moment van berekenen, in de browser met de actuele antwoorden. Die kunnen iets verschillen.

## De API-response

`GET /analyse?api=<url van de api>&gesprek_id=<id>[&herbereken=1]` (op de math server)

```json
{
  "gesprek_id": "…",
  "model": {
    "berekend": "2026-09-23T23:24:00+00:00",
    "verloopt": "2026-09-24T05:24:00+00:00",
    "max_leeftijd": 21600,
    "stellingen": ["<stelling_id>", "…"],
    "gemiddelden": [0.12, "…"],
    "componenten": [["…"], ["…"], ["…"]],
    "centra": [[1.2, -0.4, 0.1], "…"]
  },
  "k": 4,
  "k_gekozen_op": "kenmerkend",
  "verklaarde_variantie": [0.1308, 0.0546, 0.0364],
  "min_antwoorden": 7,
  "buiten_analyse": 45,
  "deelnemers": [{ "deelnemer": 1, "antwoorden": 25, "x": 1.38, "y": 0.27, "groep": 1 }],
  "groepen": [{ "naam": "A", "deelnemers": 263, "stellingen": { "<stelling_id>": { "eens": 40, "neutraal": 5, "oneens": 3 } } }]
}
```

`deelnemer` is het volgnummer in de matrix (`GET /antwoorden?gesprek_id=…` op de API; de export gebruikt dezelfde nummers), op volgorde van het eerste antwoord. User-id's komen niet in de response.

## Instellingen

| Constante | Waarde | Waar |
|---|---|---|
| `MIN_ANTWOORDEN` | 7 | `Analyse.php`: minimum om mee te tellen |
| `DIMENSIES` | 3 | `Analyse.php`: PCA-richtingen voor de groepen |
| `K_KANDIDATEN`, `K_MAX` | 3, 4, 5 | `Analyse.php` |
| `KENMERKEND_DREMPEL`, `KENMERKEND_VERSCHIL` | 0,9 en 0,3 | `Analyse.php` én `analyse.js` |
| `KENMERKEND_MIN_ANTWOORDEN`, `KENMERKEND_MIN_DEEL` | 3 en 0,2 | `Analyse.php` én `analyse.js` (`MIN_ANTWOORDEN`, `MIN_DEEL`) |
| `EENSGEZIND_DREMPEL` | 0,7 | `analyse.js`: "verder eens of oneens" en consensus |
| `MAX_LEEFTIJD` | 6 uur | `AnalyseModel.php` |

## Snelheid

Gemeten met 1000 deelnemers, 200 stellingen en 34.362 antwoorden, op de ingebouwde PHP-server:

| Wat | Tijd |
|---|---|
| Model berekenen (hooguit eens per 6 uur) | ± 1,0 s |
| `GET /analyse` met opgeslagen model, inclusief het ophalen van de export | ± 100 ms |
| `GET /export` (1,7 MB) en `GET /antwoorden` (de matrix, 1,6 MB) op de API | elk ± 55 ms |

De math server haalt de export bij elk verzoek opnieuw op, zodat nieuwe antwoorden direct meetellen. Dat is ongeveer de helft van de tijd van `GET /analyse`; bij grotere gesprekken is een korte cache van de export een voor de hand liggende versnelling.

Het dure deel is de covariantiematrix (deelnemers × stellingen²). De silhouette-score (deelnemers²) wordt alleen berekend als geen enkele K voor elke groep iets kenmerkends oplevert. Bij veel grotere gesprekken is dat de eerste plek om te versnellen, bijvoorbeeld door de silhouette op een steekproef te berekenen.

## Beperkingen

- **Getest op simulaties, niet op echte gesprekken.** De typen in de simulaties zijn zelf bedacht. Echte data kan zich anders gedragen, en de drempels (90%, 30 procentpunt, 70%) zijn keuzes, geen wetten.
- **Te streng bij rommelige data.** Met veel ruis haalt geen enkele groep 90%. Dan valt de keuze van K terug op de silhouette, en toont de pagina bij sommige groepen "niets kenmerkends".
- **Wat de plot niet laat zien.** De plot toont 2 van de 3 richtingen, dus een scheiding die alleen op de derde richting zit, is niet te zien.
- **Nieuwe stellingen tellen pas later mee:** pas na de volgende herberekening (hooguit 6 uur).
- **Afgekeurde stellingen verdwijnen pas later uit het model:** alleen [zichtbare stellingen](../README.md#moderatie) tellen mee, maar een stelling die na de laatste berekening is afgekeurd, zit nog in het model tot de volgende herberekening. Wel valt hij direct weg uit de groepsstatistiek (kenmerkend en consensus).
