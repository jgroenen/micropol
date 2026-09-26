"""Every call of the api and the math server, checked against their OpenAPI spec: the status must
be the expected one and in the spec, and the request and response must fit the schema. Every operation in
the specs must be called at least once. The test makes its own data (gesprekken, stellingen, antwoorden)
through the api; run it with tests/run.sh, which puts the data back afterwards.
"""
import base64, json, os, re, ssl, sys, urllib.error, urllib.parse, urllib.request
from jsonschema import Draft202012Validator

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPECS = {naam: json.load(open(f'{ROOT}/{naam}/openapi.json')) for naam in ('api', 'math')}
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


def schemafouten(spec, schema, data, wat):
    wortel = dict(schema, components=spec['components'])
    return [f'{wat} {"/".join(map(str, e.absolute_path))}: {e.message[:120]}' for e in Draft202012Validator(wortel).iter_errors(data)]


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
            meldingen += schemafouten(spec, vraag, body, 'request')
        antwoord = operatie['responses'][str(status)]
        while '$ref' in antwoord:
            antwoord = spec['components']['responses'][antwoord['$ref'].split('/')[-1]]
        schema = antwoord.get('content', {}).get('application/json', {}).get('schema')
        if schema:
            meldingen += schemafouten(spec, schema, json.loads(tekst), 'response')
        elif 'text/html' in antwoord.get('content', {}) and 'text/html' not in headers.get('Content-Type', ''):
            meldingen.append(f"content-type {headers.get('Content-Type')}")
    fouten += bool(meldingen)
    print(f"{'FOUT' if meldingen else 'ok  '} {dienst} {methode} {pad[:70]} {status} {'; '.join(meldingen[:3])}")
    if status in (301, 302):
        return headers
    return json.loads(tekst) if tekst and 'json' in headers.get('Content-Type', '') else None


# ---- api: logging in
check('api', 'GET', '/sessie', 200)
check('api', 'POST', '/sessie', 400, {})
check('api', 'POST', '/sessie', 401, {'gebruikersnaam': GEBRUIKER, 'wachtwoord': 'fout'})
check('api', 'POST', '/sessie', 401, {'gebruikersnaam': 'bestaat-niet', 'wachtwoord': 'fout'})
TOKEN = check('api', 'POST', '/sessie', 200, {'gebruikersnaam': GEBRUIKER, 'wachtwoord': WACHTWOORD})['token']
if check('api', 'GET', '/sessie', 200, token=TOKEN)['beheerder']['gebruikersnaam'] != GEBRUIKER:
    print('FOUT GET /sessie geeft een andere beheerder'); fouten += 1
if check('api', 'GET', '/sessie', 200, token='a' * 64)['beheerder'] is not None:
    print('FOUT GET /sessie accepteert een onbekend token'); fouten += 1

# ---- api: gesprekken (the test makes its own)
check('api', 'POST', '/gesprekken', 401, {'titel': 'x'})
check('api', 'POST', '/gesprekken', 400, {'titel': ''}, token=TOKEN)
G = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek', 'omschrijving': 'Van tests/api.py.'}, token=TOKEN)['id']
VOORAF = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek vooraf', 'moderatie': 'vooraf'}, token=TOKEN)['id']
check('api', 'PUT', f'/gesprekken/{G}', 200, {'titel': 'Testgesprek (aangepast)'}, token=TOKEN)
check('api', 'PUT', '/gesprekken/bestaatniet', 404, {'titel': 'x'}, token=TOKEN)
check('api', 'PUT', f'/gesprekken/{G}', 401, {'titel': 'x'})
check('api', 'GET', '/gesprekken', 200)
check('api', 'GET', '/gesprekken/bestaatniet', 404)

# ---- api: stellingen and antwoorden, from a few deelnemers
stellingen = [check('api', 'POST', '/stellingen', 201, {'gesprek_id': G, 'deelnemer_id': 'deelnemer-1', 'tekst': f'Teststelling {i}.'})['id'] for i in range(1, 9)]
check('api', 'POST', '/stellingen', 400, {'gesprek_id': G})
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
check('api', 'POST', '/antwoorden', 404, {'gesprek_id': G, 'deelnemer_id': 'x', 'stelling_id': wacht['id'], 'waarde': 'eens'})
check('api', 'POST', '/antwoorden', 404, {'gesprek_id': 'bestaatniet', 'deelnemer_id': 'x', 'stelling_id': 'x', 'waarde': 'eens'})
check('api', 'GET', f'/antwoorden?gesprek_id={G}&deelnemer_id=deelnemer-1', 200)
check('api', 'GET', f'/antwoorden?gesprek_id={G}', 200)
check('api', 'GET', '/antwoorden', 400)
check('api', 'GET', '/antwoorden?gesprek_id=bestaatniet', 404)

# ---- api: moderatie
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 200, token=TOKEN)
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 401)
check('api', 'GET', '/beoordelingen', 400, token=TOKEN)
check('api', 'GET', '/beoordelingen?gesprek_id=bestaatniet', 404, token=TOKEN)
check('api', 'POST', '/beoordelingen', 200, {'gesprek_id': VOORAF, 'stelling_id': wacht['id'], 'beoordeling': 'goedgekeurd'}, token=TOKEN)
check('api', 'POST', '/beoordelingen', 200, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'afgekeurd', 'reden': 'Test'}, token=TOKEN)
check('api', 'POST', '/beoordelingen', 400, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'afgekeurd'}, token=TOKEN)
check('api', 'POST', '/beoordelingen', 401, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'goedgekeurd'})
check('api', 'POST', '/beoordelingen', 404, {'gesprek_id': G, 'stelling_id': 'bestaatniet', 'beoordeling': 'goedgekeurd'}, token=TOKEN)

# ---- api: export and docs
export = check('api', 'GET', f'/export?gesprek_id={G}', 200)
if len(export['stellingen']) != len(stellingen) - 1:
    print('FOUT de afgekeurde stelling staat in de export'); fouten += 1
check('api', 'GET', '/export', 400)
check('api', 'GET', '/export?gesprek_id=bestaatniet', 404)
check('api', 'GET', '/docs', 200)
check('api', 'GET', '/docs/openapi.json', 200)

# ---- math: the analyse of the test gesprek
check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id={G}", 200)
check('math', 'GET', '/analyse', 400)
check('math', 'GET', f'/analyse?api=http://evil.example&gesprek_id={G}', 403)
check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id=bestaatniet", 404)
check('math', 'GET', '/docs', 200)
check('math', 'GET', '/docs/openapi.json', 200)

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
    json.dump({'gesprek': G, 'deelnemer': 'deelnemer-1', 'deelnemers': 4, 'zichtbaar': len(stellingen) - 1}, open(uitvoer, 'w'))

print(f'api.py: {fouten} fouten')
sys.exit(1 if fouten else 0)
