"""Every call of the api and the math server, checked against their OpenAPI spec: the status must
be the expected one and in the spec, and the request and response must fit the schema. Every operation in
the specs must be called at least once. The test starts on an empty api (installing with admin/admin) and
makes its own data through the api: accounts and teams, gesprekken, stellingen, antwoorden; run it with
tests/run.sh, which empties the data first and puts it back afterwards.
"""
import base64, json, os, re, ssl, sys, urllib.error, urllib.parse, urllib.request
from jsonschema import Draft202012Validator
from referencing import Registry, Resource
from referencing.jsonschema import DRAFT202012

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPECS = {naam: json.load(open(f'{ROOT}/{naam}/openapi.json')) for naam in ('api', 'math')}
# the types, in schema.json next to each spec; the spec refers to them as schema.json#/$defs/...
SCHEMAS = {naam: json.load(open(f'{ROOT}/{naam}/schema.json')) for naam in SPECS}
REGISTERS = {naam: Registry().with_resource('schema.json', Resource.from_contents(schema, default_specification=DRAFT202012)) for naam, schema in SCHEMAS.items()}
# the servers; other urls (like a test server) through the environment
BASIS = {'api': os.environ.get('TEST_API_URL', 'http://localhost:8001'), 'math': os.environ.get('TEST_MATH_URL', 'http://localhost:8004')}
GEBRUIKER = os.environ.get('TEST_GEBRUIKER', 'minipol-test')
WACHTWOORD = os.environ.get('TEST_WACHTWOORD', 'testwachtwoord123')

fouten = 0
gedekt = set()


class GeenRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


# TEST_ONVEILIG_TLS=1: accept a certificate of a local CA (Caddy's local_certs)
context = ssl._create_unverified_context() if os.environ.get('TEST_ONVEILIG_TLS') else None
urllib.request.install_opener(urllib.request.build_opener(GeenRedirect, urllib.request.HTTPSHandler(context=context)))


def verzoek(dienst, methode, pad, body=None, token=None, formulier=False, basic=None):
    headers = {'Content-Type': 'application/x-www-form-urlencoded' if formulier else 'application/json'}
    if token:
        headers['Authorization'] = f'Bearer {token}'
    if basic:
        headers['Authorization'] = 'Basic ' + base64.b64encode(basic.encode()).decode()
    data = None if body is None else (urllib.parse.urlencode(body) if formulier else json.dumps(body)).encode()
    request = urllib.request.Request(BASIS[dienst] + pad, data=data, method=methode, headers=headers)
    try:
        with urllib.request.urlopen(request) as response:
            return response.status, dict(response.headers), response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, dict(error.headers), error.read().decode()


def schemafouten(dienst, schema, data, wat):
    wortel = dict(schema, components=SPECS[dienst]['components'])
    validator = Draft202012Validator(wortel, registry=REGISTERS[dienst])
    return [f'{wat} {"/".join(map(str, e.absolute_path))}: {e.message[:120]}' for e in validator.iter_errors(data)]


def check(dienst, methode, pad, verwacht, body=None, token=None, formulier=False, basic=None):
    """one call, checked against the spec; returns the json of the response, or the headers for a redirect"""
    global fouten
    spec = SPECS[dienst]
    status, headers, tekst = verzoek(dienst, methode, pad, body, token, formulier, basic)
    kaal = pad.split('?')[0]
    sjabloon = next((p for p in spec['paths'] if re.fullmatch(re.sub(r'\{[^}]+\}', '[^/]+', p), kaal)), None)
    operatie = spec['paths'].get(sjabloon, {}).get(methode.lower()) if sjabloon else None
    meldingen = []
    if status != verwacht:
        meldingen.append(f'status {status}, verwacht {verwacht}: {tekst[:120]}')
    elif operatie is None:
        meldingen.append('niet in de spec')
    elif str(status) not in operatie['responses']:
        meldingen.append(f'status {status} niet in de spec')
    else:
        gedekt.add((dienst, methode, sjabloon))
        vraag = operatie.get('requestBody', {}).get('content', {}).get('application/json', {}).get('schema')
        if vraag and body is not None and not formulier and status < 300:
            meldingen += schemafouten(dienst, vraag, body, 'request')
        antwoord = operatie['responses'][str(status)]
        while '$ref' in antwoord:
            antwoord = spec['components']['responses'][antwoord['$ref'].split('/')[-1]]
        schema = antwoord.get('content', {}).get('application/json', {}).get('schema')
        if schema:
            meldingen += schemafouten(dienst, schema, json.loads(tekst), 'response')
        elif 'text/html' in antwoord.get('content', {}) and 'text/html' not in headers.get('Content-Type', ''):
            meldingen.append(f"content-type {headers.get('Content-Type')}")
    fouten += bool(meldingen)
    print(f"{'FOUT' if meldingen else 'ok  '} {dienst} {methode} {pad[:70]} {status} {'; '.join(meldingen[:3])}")
    if status in (301, 302):
        return headers
    return json.loads(tekst) if tekst and 'json' in headers.get('Content-Type', '') else None


# ---- the types: schema.json is a valid JSON Schema, and served with its own url as $id
for dienst, schema in SCHEMAS.items():
    try:
        Draft202012Validator.check_schema(schema)
        print(f'ok   {dienst}/schema.json is een geldig JSON Schema')
    except Exception as error:
        print(f'FOUT {dienst}/schema.json: {error}'); fouten += 1
    live = check(dienst, 'GET', '/docs/schema.json', 200)
    if live.get('$id') != BASIS[dienst] + '/docs/schema.json' or live.get('$defs') != schema['$defs']:
        print(f'FOUT {dienst}: /docs/schema.json heeft niet de eigen url als $id, of andere typen'); fouten += 1

# ---- api: installing: on an empty server admin/admin logs in, only to make the first superbeheerder
check('api', 'GET', '/sessie', 200)
check('api', 'POST', '/sessie', 400, {})
INSTALLATIE = check('api', 'POST', '/sessie', 200, {'gebruikersnaam': 'admin', 'wachtwoord': 'admin'})
if INSTALLATIE['account'] is not None or not INSTALLATIE['installatie']:
    print('FOUT admin/admin geeft na installatie niet de installatielogin'); fouten += 1
check('api', 'POST', '/gesprekken', 401, {'titel': 'x'}, token=INSTALLATIE['token'])
check('api', 'POST', '/installatie', 400, {'gebruikersnaam': GEBRUIKER, 'email': 'super@example.org', 'wachtwoord': 'kort'}, token=INSTALLATIE['token'])
check('api', 'POST', '/installatie', 409, {'gebruikersnaam': 'admin', 'email': 'super@example.org', 'wachtwoord': WACHTWOORD}, token=INSTALLATIE['token'])
SUPER = check('api', 'POST', '/installatie', 201, {'gebruikersnaam': GEBRUIKER, 'email': 'super@example.org', 'wachtwoord': WACHTWOORD}, token=INSTALLATIE['token'])['token']
check('api', 'POST', '/installatie', 401, {'gebruikersnaam': 'nog-een', 'email': 'x@example.org', 'wachtwoord': WACHTWOORD}, token=INSTALLATIE['token'])
check('api', 'POST', '/sessie', 401, {'gebruikersnaam': 'admin', 'wachtwoord': 'admin'})

# ---- api: logging in
check('api', 'POST', '/sessie', 401, {'gebruikersnaam': GEBRUIKER, 'wachtwoord': 'fout'})
check('api', 'POST', '/sessie', 401, {'gebruikersnaam': 'bestaat-niet', 'wachtwoord': 'fout'})
TOKEN = check('api', 'POST', '/sessie', 200, {'gebruikersnaam': GEBRUIKER, 'wachtwoord': WACHTWOORD})['token']
sessie = check('api', 'GET', '/sessie', 200, token=TOKEN)
if sessie['account']['gebruikersnaam'] != GEBRUIKER or not sessie['account']['superbeheerder']:
    print('FOUT GET /sessie geeft niet de superbeheerder'); fouten += 1
if check('api', 'GET', '/sessie', 200, token='a' * 64)['account'] is not None:
    print('FOUT GET /sessie accepteert een onbekend token'); fouten += 1

# ---- api: gesprekken (the test makes its own; a superbeheerder makes them)
check('api', 'POST', '/gesprekken', 401, {'titel': 'x'})
check('api', 'POST', '/gesprekken', 400, {'titel': ''}, token=SUPER)
G = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek', 'omschrijving': 'Van tests/api.py.'}, token=SUPER)['id']
VOORAF = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek vooraf', 'moderatie': 'vooraf'}, token=SUPER)['id']

# ---- api: the team, with uitnodigingen: a gespreksbeheerder, who invites a moderator
check('api', 'POST', '/uitnodigingen', 401, {'rol': 'gespreksbeheerder', 'gesprek_id': G})
check('api', 'POST', '/uitnodigingen', 400, {'rol': 'baas', 'gesprek_id': G}, token=SUPER)
check('api', 'POST', '/uitnodigingen', 400, {'rol': 'moderator'}, token=SUPER)
check('api', 'POST', '/uitnodigingen', 404, {'rol': 'moderator', 'gesprek_id': 'bestaatniet'}, token=SUPER)
uitnodiging = check('api', 'POST', '/uitnodigingen', 201, {'rol': 'gespreksbeheerder', 'gesprek_id': G}, token=SUPER)
if check('api', 'GET', f"/uitnodigingen/{uitnodiging['token']}", 200)['gesprek']['id'] != G:
    print('FOUT GET /uitnodigingen geeft niet het gesprek'); fouten += 1
check('api', 'GET', '/uitnodigingen/bestaatniet', 404)
GB_NAAM = GEBRUIKER + '-gb'
check('api', 'POST', f"/uitnodigingen/{uitnodiging['token']}", 409, {'gebruikersnaam': GEBRUIKER, 'email': 'gb@example.org', 'wachtwoord': WACHTWOORD})
check('api', 'POST', f"/uitnodigingen/{uitnodiging['token']}", 400, {'gebruikersnaam': GB_NAAM, 'email': 'geen-email', 'wachtwoord': WACHTWOORD})
GB = check('api', 'POST', f"/uitnodigingen/{uitnodiging['token']}", 200, {'gebruikersnaam': GB_NAAM, 'email': 'gb@example.org', 'wachtwoord': WACHTWOORD})['token']
check('api', 'POST', f"/uitnodigingen/{uitnodiging['token']}", 404, {'gebruikersnaam': 'nog-een', 'email': 'x@example.org', 'wachtwoord': WACHTWOORD})
# logged in, an uitnodiging needs no new account: the gespreksbeheerder of G joins VOORAF too
extra = check('api', 'POST', '/uitnodigingen', 201, {'rol': 'gespreksbeheerder', 'gesprek_id': VOORAF}, token=SUPER)
rollen = check('api', 'POST', f"/uitnodigingen/{extra['token']}", 200, token=GB)['account']['rollen']
if rollen != {G: 'gespreksbeheerder', VOORAF: 'gespreksbeheerder'}:
    print(f'FOUT de gespreksbeheerder heeft niet de rollen in beide gesprekken: {rollen}'); fouten += 1
MOD_NAAM = GEBRUIKER + '-mod'
moderatoruitnodiging = check('api', 'POST', '/uitnodigingen', 201, {'rol': 'moderator', 'gesprek_id': G}, token=GB)
MOD_SESSIE = check('api', 'POST', f"/uitnodigingen/{moderatoruitnodiging['token']}", 200, {'gebruikersnaam': MOD_NAAM, 'email': 'mod@example.org', 'wachtwoord': WACHTWOORD})
MOD = MOD_SESSIE['token']
check('api', 'POST', '/uitnodigingen', 403, {'rol': 'moderator', 'gesprek_id': G}, token=MOD)
check('api', 'POST', '/uitnodigingen', 403, {'rol': 'superbeheerder'}, token=GB)
if [g['rol'] for g in check('api', 'GET', '/gesprekken', 200, token=MOD)['gesprekken']] != ['moderator']:
    print('FOUT een moderator ziet in GET /gesprekken niet alleen zijn eigen gesprek'); fouten += 1

# ---- api: changing a gesprek: its gespreksbeheerders only
check('api', 'PUT', f'/gesprekken/{G}', 200, {'titel': 'Testgesprek (aangepast)'}, token=GB)
# nothing changed: no event
check('api', 'PUT', f'/gesprekken/{G}', 200, {'titel': 'Testgesprek (aangepast)'}, token=GB)
check('api', 'PUT', f'/gesprekken/{G}', 403, {'titel': 'x'}, token=MOD)
check('api', 'PUT', f'/gesprekken/{G}', 403, {'titel': 'x'}, token=SUPER)
check('api', 'PUT', '/gesprekken/bestaatniet', 404, {'titel': 'x'}, token=GB)
check('api', 'PUT', f'/gesprekken/{G}', 401, {'titel': 'x'})
check('api', 'GET', '/gesprekken', 200)
check('api', 'GET', '/gesprekken/bestaatniet', 404)

# ---- api: the team: pausing and restoring a lid
if [l['gebruikersnaam'] for l in check('api', 'GET', f'/team?gesprek_id={G}', 200, token=GB)['leden']] != [GB_NAAM, MOD_NAAM]:
    print('FOUT GET /team geeft niet de gespreksbeheerder en de moderator'); fouten += 1
check('api', 'GET', f'/team?gesprek_id={G}', 200, token=SUPER)
check('api', 'GET', f'/team?gesprek_id={G}', 403, token=MOD)
check('api', 'GET', f'/team?gesprek_id={G}', 401)
check('api', 'GET', '/team', 400, token=GB)
check('api', 'GET', '/team?gesprek_id=bestaatniet', 404, token=GB)
MOD_ID = MOD_SESSIE['account']['id']
check('api', 'POST', '/team', 400, {'gesprek_id': G, 'account_id': MOD_ID, 'status': 'opgeschort'}, token=GB)
check('api', 'POST', '/team', 200, {'gesprek_id': G, 'account_id': MOD_ID, 'status': 'opgeschort', 'reden': 'Test'}, token=GB)
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 403, token=MOD)
check('api', 'POST', '/team', 200, {'gesprek_id': G, 'account_id': MOD_ID, 'status': 'actief'}, token=SUPER)
check('api', 'POST', '/team', 409, {'gesprek_id': G, 'account_id': check('api', 'GET', '/sessie', 200, token=GB)['account']['id'], 'status': 'opgeschort', 'reden': 'Zelf'}, token=GB)
check('api', 'POST', '/team', 404, {'gesprek_id': G, 'account_id': 'bestaatniet', 'status': 'actief'}, token=GB)
# a superbeheerder who is in the team too, paused by a gespreksbeheerder, restores himself
eigen = check('api', 'POST', '/uitnodigingen', 201, {'rol': 'moderator', 'gesprek_id': VOORAF}, token=SUPER)
check('api', 'POST', f"/uitnodigingen/{eigen['token']}", 200, token=SUPER)
check('api', 'POST', '/team', 200, {'gesprek_id': VOORAF, 'account_id': sessie['account']['id'], 'status': 'opgeschort', 'reden': 'Test'}, token=GB)
check('api', 'POST', '/team', 200, {'gesprek_id': VOORAF, 'account_id': sessie['account']['id'], 'status': 'actief'}, token=SUPER)
check('api', 'POST', '/team', 403, {'gesprek_id': G, 'account_id': MOD_ID, 'status': 'actief'}, token=MOD)
check('api', 'POST', '/team', 401, {'gesprek_id': G, 'account_id': MOD_ID, 'status': 'actief'})

# ---- api: superbeheerders: a second one, paused and restored
check('api', 'GET', '/superbeheerders', 200, token=SUPER)
check('api', 'GET', '/superbeheerders', 403, token=GB)
check('api', 'GET', '/superbeheerders', 401)
tweede = check('api', 'POST', '/uitnodigingen', 201, {'rol': 'superbeheerder'}, token=SUPER)
TWEEDE_ID = check('api', 'POST', f"/uitnodigingen/{tweede['token']}", 200, {'gebruikersnaam': GEBRUIKER + '-super2', 'email': 'super2@example.org', 'wachtwoord': WACHTWOORD})['account']['id']
check('api', 'POST', '/superbeheerders', 200, {'account_id': TWEEDE_ID, 'status': 'opgeschort', 'reden': 'Test'}, token=SUPER)
check('api', 'POST', '/superbeheerders', 200, {'account_id': TWEEDE_ID, 'status': 'actief'}, token=SUPER)
check('api', 'POST', '/superbeheerders', 409, {'account_id': sessie['account']['id'], 'status': 'opgeschort', 'reden': 'Zelf'}, token=SUPER)
check('api', 'POST', '/superbeheerders', 404, {'account_id': 'bestaatniet', 'status': 'actief'}, token=SUPER)
check('api', 'POST', '/superbeheerders', 400, {'account_id': TWEEDE_ID, 'status': 'weg'}, token=SUPER)
check('api', 'POST', '/superbeheerders', 403, {'account_id': TWEEDE_ID, 'status': 'actief'}, token=GB)
check('api', 'POST', '/superbeheerders', 401, {'account_id': TWEEDE_ID, 'status': 'actief'})

# ---- api: pausing and ending a gesprek: its own test gesprekken
PAUZE = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek gepauzeerd'}, token=SUPER)['id']
pauzestelling = check('api', 'POST', '/stellingen', 201, {'gesprek_id': PAUZE, 'deelnemer_id': 'deelnemer-1', 'tekst': 'Voor de pauze.'})['id']
check('api', 'POST', '/gespreksstatus', 400, {'gesprek_id': PAUZE, 'status': 'opgeschort'}, token=SUPER)
check('api', 'POST', '/gespreksstatus', 403, {'gesprek_id': PAUZE, 'status': 'opgeschort', 'reden': 'Test'}, token=GB)
check('api', 'POST', '/gespreksstatus', 401, {'gesprek_id': PAUZE, 'status': 'opgeschort', 'reden': 'Test'})
check('api', 'POST', '/gespreksstatus', 404, {'gesprek_id': 'bestaatniet', 'status': 'opgeschort', 'reden': 'Test'}, token=SUPER)
check('api', 'POST', '/gespreksstatus', 200, {'gesprek_id': PAUZE, 'status': 'opgeschort', 'reden': 'Test'}, token=SUPER)
gepauzeerd = check('api', 'GET', f'/gesprekken/{PAUZE}', 200)
if gepauzeerd['status'] != 'opgeschort' or gepauzeerd['stellingen']:
    print('FOUT een gepauzeerd gesprek geeft nog stellingen'); fouten += 1
if PAUZE in [g['id'] for g in check('api', 'GET', '/gesprekken', 200)['gesprekken']]:
    print('FOUT een gepauzeerd gesprek staat in GET /gesprekken voor deelnemers'); fouten += 1
check('api', 'POST', '/stellingen', 409, {'gesprek_id': PAUZE, 'deelnemer_id': 'deelnemer-1', 'tekst': 'Tijdens de pauze.'})
check('api', 'POST', '/antwoorden', 409, {'gesprek_id': PAUZE, 'deelnemer_id': 'deelnemer-1', 'stelling_id': pauzestelling, 'waarde': 'eens'})
# a gesprek is never removed, it is ended (beeindigd): like paused, with another notice in the app
check('api', 'POST', '/gespreksstatus', 400, {'gesprek_id': PAUZE, 'status': 'verwijderd', 'reden': 'Test'}, token=SUPER)
VOORBIJ = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek beëindigd'}, token=SUPER)['id']
check('api', 'POST', '/gespreksstatus', 400, {'gesprek_id': VOORBIJ, 'status': 'beeindigd'}, token=SUPER)
check('api', 'POST', '/gespreksstatus', 200, {'gesprek_id': VOORBIJ, 'status': 'beeindigd', 'reden': 'Test'}, token=SUPER)
if check('api', 'GET', f'/gesprekken/{VOORBIJ}', 200)['status'] != 'beeindigd':
    print('FOUT een beëindigd gesprek heeft niet de status beeindigd'); fouten += 1
check('api', 'POST', '/stellingen', 409, {'gesprek_id': VOORBIJ, 'deelnemer_id': 'deelnemer-1', 'tekst': 'Na het einde.'})
if VOORBIJ in [g['id'] for g in check('api', 'GET', '/gesprekken', 200)['gesprekken']]:
    print('FOUT een beëindigd gesprek staat in GET /gesprekken voor deelnemers'); fouten += 1
if VOORBIJ not in [g['id'] for g in check('api', 'GET', '/gesprekken', 200, token=SUPER)['gesprekken']]:
    print('FOUT een superbeheerder ziet een beëindigd gesprek niet'); fouten += 1
# opening it again
check('api', 'POST', '/gespreksstatus', 200, {'gesprek_id': VOORBIJ, 'status': 'actief'}, token=SUPER)
check('api', 'POST', '/gespreksstatus', 200, {'gesprek_id': VOORBIJ, 'status': 'beeindigd', 'reden': 'Echt voorbij'}, token=SUPER)

# ---- api: stellingen and antwoorden, from a few deelnemers
stellingen = [check('api', 'POST', '/stellingen', 201, {'gesprek_id': G, 'deelnemer_id': 'deelnemer-1', 'tekst': f'Teststelling {i}.'})['id'] for i in range(1, 9)]
check('api', 'POST', '/stellingen', 400, {'gesprek_id': G})
check('api', 'POST', '/stellingen', 400, {'gesprek_id': G, 'deelnemer_id': 'x' * 65, 'tekst': 'Te lang id.'})
check('api', 'POST', '/stellingen', 400, {'gesprek_id': G, 'deelnemer_id': '../x', 'tekst': 'Vreemd id.'})
check('api', 'POST', '/stellingen', 404, {'gesprek_id': 'bestaatniet', 'deelnemer_id': 'x', 'tekst': 'x'})
wacht = check('api', 'POST', '/stellingen', 201, {'gesprek_id': VOORAF, 'deelnemer_id': 'deelnemer-1', 'tekst': 'Wacht op goedkeuring.'})
if wacht['zichtbaar']:
    print('FOUT een stelling in een gesprek met moderatie vooraf is meteen zichtbaar'); fouten += 1
check('api', 'GET', f'/stellingen?gesprek_id={G}&deelnemer_id=deelnemer-1', 200)
check('api', 'GET', f'/stellingen?gesprek_id={G}', 400)
check('api', 'GET', '/stellingen?gesprek_id=bestaatniet&deelnemer_id=x', 404)
check('api', 'GET', f'/gesprekken/{G}', 200)
for d in range(1, 5):
    for i, s in enumerate(stellingen):
        check('api', 'POST', '/antwoorden', 201, {'gesprek_id': G, 'deelnemer_id': f'deelnemer-{d}', 'stelling_id': s, 'waarde': ['eens', 'neutraal', 'oneens'][(i + d) % 3]})
check('api', 'POST', '/antwoorden', 400, {'gesprek_id': G, 'deelnemer_id': 'x', 'stelling_id': stellingen[0], 'waarde': 'misschien'})
check('api', 'POST', '/antwoorden', 400, {'gesprek_id': G, 'deelnemer_id': 'x' * 65, 'stelling_id': stellingen[0], 'waarde': 'eens'})
check('api', 'GET', f"/antwoorden?gesprek_id={G}&deelnemer_id={'x' * 65}", 400)
check('api', 'POST', '/antwoorden', 404, {'gesprek_id': G, 'deelnemer_id': 'x', 'stelling_id': wacht['id'], 'waarde': 'eens'})
check('api', 'POST', '/antwoorden', 404, {'gesprek_id': 'bestaatniet', 'deelnemer_id': 'x', 'stelling_id': 'x', 'waarde': 'eens'})
check('api', 'GET', f'/antwoorden?gesprek_id={G}&deelnemer_id=deelnemer-1', 200)
check('api', 'GET', f'/antwoorden?gesprek_id={G}', 200)
check('api', 'GET', '/antwoorden', 400)
check('api', 'GET', '/antwoorden?gesprek_id=bestaatniet', 404)

# ---- api: moderatie
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 200, token=MOD)
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 403, token=SUPER)
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 401)
check('api', 'GET', '/beoordelingen', 400, token=MOD)
check('api', 'GET', '/beoordelingen?gesprek_id=bestaatniet', 404, token=MOD)
check('api', 'POST', '/beoordelingen', 200, {'gesprek_id': VOORAF, 'stelling_id': wacht['id'], 'beoordeling': 'goedgekeurd'}, token=GB)
check('api', 'POST', '/beoordelingen', 403, {'gesprek_id': VOORAF, 'stelling_id': wacht['id'], 'beoordeling': 'goedgekeurd'}, token=MOD)
check('api', 'POST', '/beoordelingen', 200, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'afgekeurd', 'reden': 'Test'}, token=MOD)
check('api', 'POST', '/beoordelingen', 400, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'afgekeurd'}, token=MOD)
check('api', 'POST', '/beoordelingen', 401, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'goedgekeurd'})
check('api', 'POST', '/beoordelingen', 404, {'gesprek_id': G, 'stelling_id': 'bestaatniet', 'beoordeling': 'goedgekeurd'}, token=MOD)

# ---- api: the logboek (events)
events = check('api', 'GET', f'/events?gesprek_id={G}', 200, token=GB)['events']
ids = [e['id'] for e in events]
typen = [e['type'] for e in events]
if ids != sorted(ids, reverse=True):
    print('FOUT GET /events is niet nieuwste eerst'); fouten += 1
for soort in ('gesprek.aangemaakt', 'gesprek.aangepast', 'stelling.toegevoegd', 'stelling.afgekeurd', 'antwoord.gegeven', 'lid.toegevoegd', 'lid.opgeschort', 'lid.hersteld'):
    if soort not in typen:
        print(f'FOUT GET /events mist {soort}'); fouten += 1
if typen.count('gesprek.aangepast') != 1:
    print('FOUT een PUT zonder wijziging geeft een event'); fouten += 1
if typen.count('antwoord.gegeven') != 4 * len(stellingen):
    print('FOUT GET /events heeft niet alle antwoorden'); fouten += 1
if 'deelnemer-' in json.dumps(events):
    print('FOUT GET /events geeft de id van een deelnemer'); fouten += 1
if {e['door']['nummer'] for e in events if e['type'] == 'antwoord.gegeven'} != {1, 2, 3, 4}:
    print('FOUT de deelnemers in GET /events hebben niet de nummers van de matrix'); fouten += 1
afgekeurd = next(e for e in events if e['type'] == 'stelling.afgekeurd')
if afgekeurd['door'].get('gebruikersnaam') != MOD_NAAM:
    print('FOUT een beoordeling in GET /events heeft niet de moderator'); fouten += 1
if MOD_NAAM not in [e.get('gebruikersnaam') for e in events if e['type'] == 'lid.opgeschort']:
    print('FOUT een event van het team in GET /events heeft niet de gebruikersnaam van het lid'); fouten += 1
if afgekeurd.get('tekst') != 'Teststelling 8.' or not all(e.get('tekst', '').startswith('Teststelling') for e in events if e['type'] == 'antwoord.gegeven'):
    print('FOUT events over een stelling in GET /events hebben niet de tekst ervan'); fouten += 1
pagina = check('api', 'GET', f'/events?gesprek_id={G}&limiet=5', 200, token=GB)
volgende = check('api', 'GET', f"/events?gesprek_id={G}&limiet=5&voor={pagina['events'][-1]['id']}", 200, token=GB)
if not pagina['meer'] or [e['id'] for e in pagina['events'] + volgende['events']] != ids[:10]:
    print('FOUT GET /events met limiet en voor geeft niet de volgende events'); fouten += 1
check('api', 'GET', f'/events?gesprek_id={G}', 401)
check('api', 'GET', f'/events?gesprek_id={G}', 403, token=SUPER)
check('api', 'GET', '/events', 400, token=GB)
check('api', 'GET', f'/events?gesprek_id={G}&limiet=0', 400, token=GB)
check('api', 'GET', '/events?gesprek_id=bestaatniet', 404, token=GB)

# ---- api: export and docs
export = check('api', 'GET', f'/export?gesprek_id={G}', 200)
if len(export['stellingen']) != len(stellingen) - 1:
    print('FOUT de afgekeurde stelling staat in de export'); fouten += 1
check('api', 'GET', '/export', 400)
check('api', 'GET', '/export?gesprek_id=bestaatniet', 404)
check('api', 'GET', '/docs/openapi.json', 200)

# ---- math: the analyse of the test gesprek
analyse = check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id={G}", 200)
# a forced recalculation only when the model is at least 5 minutes old, so not right after the first
herberekend = check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id={G}&herbereken=1", 200)
if herberekend['model']['berekend'] != analyse['model']['berekend']:
    print('FOUT herbereken=1 rekent opnieuw terwijl het model net berekend is'); fouten += 1
check('math', 'GET', '/analyse', 400)
check('math', 'GET', f'/analyse?api=http://evil.example&gesprek_id={G}', 403)
check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id=bestaatniet", 404)
check('math', 'GET', '/docs/openapi.json', 200)

# ---- docs: /docs goes to the viewer with the url of the spec, which everyone may read (CORS *)
for dienst in ('api', 'math'):
    locatie = check(dienst, 'GET', '/docs', 302).get('Location', '')
    if not locatie.startswith('https://petstore.swagger.io/?url=') or urllib.parse.unquote(locatie.split('url=', 1)[1]) != BASIS[dienst] + '/docs/openapi.json':
        print(f'FOUT {dienst}: /docs stuurt niet door naar de viewer met de spec: {locatie}'); fouten += 1
    for pad in ('/docs/openapi.json', '/docs/schema.json'):
        if verzoek(dienst, 'GET', pad)[1].get('Access-Control-Allow-Origin') != '*':
            print(f'FOUT {dienst}: {pad} zonder Access-Control-Allow-Origin: *'); fouten += 1

# ---- api: logging out ends the token
UIT = check('api', 'POST', '/sessie', 200, {'gebruikersnaam': GEBRUIKER, 'wachtwoord': WACHTWOORD})['token']
check('api', 'DELETE', '/sessie', 204, token=UIT)
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 401, token=UIT)
check('api', 'DELETE', '/sessie', 204, token=TOKEN)

# every operation of the specs called at least once
for dienst, spec in SPECS.items():
    for pad, operaties in spec['paths'].items():
        for methode in operaties:
            if methode in ('get', 'post', 'put', 'delete') and (dienst, methode.upper(), pad) not in gedekt:
                print('FOUT niet getest:', dienst, methode.upper(), pad)
                fouten += 1

# for tests/browser.mjs
uitvoer = os.environ.get('TEST_UITVOER')
if uitvoer:
    json.dump({
        'gesprek': G, 'gepauzeerd': PAUZE, 'beeindigd': VOORBIJ, 'deelnemer': 'deelnemer-1', 'deelnemers': 4, 'zichtbaar': len(stellingen) - 1,
        'gespreksbeheerder': GB_NAAM, 'moderator': MOD_NAAM,
    }, open(uitvoer, 'w'))

print(f'api.py: {fouten} fouten')
sys.exit(1 if fouten else 0)
