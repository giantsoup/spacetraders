import miningYields from '../data/mining-yields.json' with { type: 'json' };
import { formatLocalTime } from './dateTime.js';

const phases = ['welcome', 'sync', 'plan', 'briefing', 'outbound', 'extract', 'inbound', 'marketSync', 'sell', 'refuel', 'review', 'complete'];
const miningTypes = ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'];
const unsafeTraits = ['UNCHARTED', 'UNSTABLE', 'DEBRIS_FIELD'];
const sorted = entries => [...entries].sort((a, b) => a.symbol.localeCompare(b.symbol, 'en'));
const pointFuel = (from, to) => from.symbol === to.symbol ? 0 : Math.ceil(Math.hypot(from.x - to.x, from.y - to.y)) + 2;
const possibleYields = ship => [...new Set(ship.mounts.flatMap(mount => mount.symbol.startsWith('MOUNT_MINING_LASER_') ? mount.deposits?.length ? mount.deposits : miningYields : []))];
const usableFuel = market => market?.tradeGoods?.find(good => good.symbol === 'FUEL' && good.purchasePrice > 0 && good.tradeVolume > 0);
const listedGoods = market => [...(market?.imports || []), ...(market?.exports || []), ...(market?.exchange || [])].map(good => good.symbol);

export function guidedKey(userId, agent) {
    return `spacetraders:guided-flight:v1:${userId}:${agent.id}:${agent.reset_id || 'unknown'}`;
}

export function newFlight(credits = null) {
    return { active: true, phase: 'welcome', plan: null, pending: null, receipts: [], scouted: [], startingCredits: credits, reserveFloor: 0, extracted: 0, sold: 0, failure: '' };
}

export function restoreFlight(value) {
    if (!value || !phases.includes(value.phase) || typeof value.active !== 'boolean' || !Array.isArray(value.receipts) || !Array.isArray(value.scouted)) return null;
    if (value.phase !== 'welcome' && value.phase !== 'sync' && value.phase !== 'plan') {
        if (!value.plan || !['mining', 'scout', 'setup'].includes(value.plan.kind) || typeof value.plan.ship !== 'string' || typeof value.plan.site !== 'string' || typeof value.plan.market !== 'string' || !Number.isSafeInteger(value.plan.reserve) || value.plan.reserve < 0) return null;
    }
    if (value.pending && (!Number.isSafeInteger(value.pending.id) || !phases.includes(value.pending.next))) return null;
    if (value.reserveFloor !== undefined && (!Number.isSafeInteger(value.reserveFloor) || value.reserveFloor < 0)) return null;
    if (![value.extracted, value.sold].every(number => Number.isSafeInteger(number) && number >= 0)) return null;
    return { ...newFlight(), ...value, reserveFloor: Math.max(value.reserveFloor || 0, value.plan?.reserve || 0), receipts: value.receipts.filter(Number.isSafeInteger), scouted: value.scouted.filter(symbol => typeof symbol === 'string') };
}

// Stable choices from saved observations. The existing server preview remains the authority.
export function chooseFlight(context, scouted = [], reserveFloor = 0) {
    const { agent, ships, capabilities, observations, guidedOffers = [] } = context;
    const points = sorted(observations.filter(entry => entry.kind === 'waypoint').map(entry => entry.data));
    const markets = sorted(observations.filter(entry => entry.kind === 'market').map(entry => ({ symbol: entry.symbol, ...entry.data })));
    const operational = sorted(ships).filter(entry => capabilities[entry.symbol] === null);
    const miners = operational.filter(entry => entry.snapshot.nav.flightMode === 'CRUISE' && entry.snapshot.cargo.units === 0);
    if (operational.length && !miners.length) return { reason: `${operational[0].symbol} is already an operational miner, so another purchase is unnecessary. It needs empty cargo and CRUISE mode for this guided trip. Current cargo: ${operational[0].snapshot.cargo.units}; mode: ${operational[0].snapshot.nav.flightMode}. Resolve those prerequisites in Fleet before starting.` };
    if (!operational.length && context.actions?.some(action => action.command === 'buy_ship' && (action.outcome?.delivered_ship || action.outcome?.unresolved_adjustment))) return { reason: 'The one-time miner purchase was already executed or acknowledged. Inspect the delivered fleet and its equipment. Guidance will not recommend another purchase.' };
    const ready = sorted(ships).filter(entry => entry.snapshot.nav.flightMode === 'CRUISE' && entry.snapshot.nav.status !== 'IN_TRANSIT');
    const routes = [];
    function routesFor(symbol, ship, kind, extra = {}) {
        const start = points.find(point => point.symbol === ship.nav.waypointSymbol);
        if (!start) return;
        const deposits = possibleYields(ship);
        for (const mine of points.filter(point => point.systemSymbol === ship.nav.systemSymbol && miningTypes.includes(point.type) && !point.traits.some(trait => unsafeTraits.includes(trait.symbol)))) {
            for (const market of markets) {
                const buyer = points.find(point => point.symbol === market.symbol && point.systemSymbol === mine.systemSymbol);
                const fuelQuote = market.tradeGoods?.find(good => good.symbol === 'FUEL' && good.purchasePrice > 0 && good.tradeVolume > 0);
                if (!buyer || !fuelQuote || !deposits.length || !deposits.every(symbol => market.tradeGoods.some(good => good.symbol === symbol && good.sellPrice > 0 && good.tradeVolume > 0))) continue;
                const fuel = pointFuel(start, mine) + pointFuel(mine, buyer);
                const canRefuel = usableFuel(markets.find(market => market.symbol === start.symbol));
                const preparation = ship.fuel.current < fuel && canRefuel ? Math.ceil((ship.fuel.capacity - ship.fuel.current) / 100) * canRefuel.purchasePrice : 0;
                const fuelBudget = Math.max(Math.ceil(ship.fuel.capacity / 100) * fuelQuote.purchasePrice * 2, preparation + Math.ceil(ship.fuel.capacity / 100) * fuelQuote.purchasePrice);
                const reserve = Math.max(reserveFloor, fuelBudget, Math.ceil(agent.credits * 0.01));
                if (ship.fuel.capacity < Math.max(fuel, pointFuel(buyer, mine) * 2) || (ship.fuel.current < fuel && !canRefuel) || agent.credits - (extra.price || 0) - fuelBudget < reserve) continue;
                routes.push({ kind, ship: symbol, site: mine.symbol, market: buyer.symbol, reserve, fuel, ...(ship.fuel.current < fuel ? { refuelAt: start.symbol } : {}), ...extra });
            }
        }
    }
    for (const entry of miners) routesFor(entry.symbol, entry.snapshot, 'mining');
    if (!operational.length) {
        for (const offer of guidedOffers) {
            const observer = ready.find(entry => entry.snapshot.nav.waypointSymbol === offer.yard);
            if (!observer) continue;
            const template = { ...offer.configuration, nav: observer.snapshot.nav, fuel: { current: offer.configuration.frame.fuelCapacity, capacity: offer.configuration.frame.fuelCapacity } };
            routesFor(observer.symbol, template, 'setup', { yard: offer.yard, type: offer.configuration.type, price: offer.configuration.purchasePrice });
        }
    }
    routes.sort((a, b) => (a.kind === 'setup' ? a.price - b.price : 0) || a.fuel - b.fuel || `${a.ship}:${a.site}:${a.market}`.localeCompare(`${b.ship}:${b.site}:${b.market}`, 'en'));
    if (routes[0]) return { plan: routes[0] };

    const scouts = [];
    for (const entry of ready) {
        const start = points.find(point => point.symbol === entry.snapshot.nav.waypointSymbol);
        if (!start) continue;
        for (const point of points.filter(point => point.systemSymbol === start.systemSymbol && !scouted.includes(point.symbol))) {
            const traits = point.traits.map(trait => trait.symbol);
            const quote = markets.find(market => market.symbol === point.symbol);
            const unknownMarket = traits.includes('MARKETPLACE') && !quote?.tradeGoods?.length;
            const unknownYard = !operational.length && traits.includes('SHIPYARD') && !guidedOffers.some(offer => offer.yard === point.symbol);
            if (!unknownMarket && !unknownYard) continue;
            const deposits = possibleYields(entry.snapshot);
            const listed = listedGoods(quote);
            const usefulBuyer = deposits.length > 0 && deposits.every(symbol => listed.includes(symbol)) && listed.includes('FUEL');
            if (operational.length && listed.length && !usefulBuyer) continue;
            const fuel = pointFuel(start, point);
            if (fuel > entry.snapshot.fuel.current || (fuel * 2 > entry.snapshot.fuel.current && !usefulBuyer)) continue;
            const fuelAllowance = fuel * 2 > entry.snapshot.fuel.current ? Math.ceil(entry.snapshot.fuel.capacity / 100) * Math.max(0, ...markets.map(market => usableFuel(market)?.purchasePrice || 0)) * 2 : 0;
            const reserve = Math.max(reserveFloor, Math.ceil(agent.credits * 0.01), fuelAllowance);
            if (fuel * 2 > entry.snapshot.fuel.current && (!fuelAllowance || agent.credits - fuelAllowance < reserve)) continue;
            scouts.push({ kind: 'scout', ship: entry.symbol, site: point.symbol, market: point.symbol, reserve, fuel, ...(fuelAllowance ? { fuelAllowance } : {}), buyerCoverage: usefulBuyer });
        }
    }
    scouts.sort((a, b) => Number(b.buyerCoverage) - Number(a.buyerCoverage) || a.fuel - b.fuel || `${a.ship}:${a.site}`.localeCompare(`${b.ship}:${b.site}`, 'en'));
    if (scouts[0]) {
        const { buyerCoverage, ...plan } = scouts[0];
        return { plan };
    }
    const reason = !miners.length
        ? 'No empty operational CRUISE miner or affordable complete miner with a funded route is observed. Synchronize to refresh offers. This client cannot install missing equipment or change flight mode yet.'
        : 'No observed same-system route has positive buyer prices for every possible laser yield, enough fuel, and a funded protected reserve. Synchronize to refresh observations. The guided trip will not extract cargo without complete buyer coverage.';
    return { reason };
}

const blocked = (body, target = 'activity') => ({ kind: 'blocked', title: 'Resolve this before continuing', body, target });
const read = (title, body, target, next) => ({ kind: 'read', title, body, target, next });
const syncStep = body => ({ kind: 'sync', target: 'sync', title: 'Click Synchronize', body });

export function flightStep(session, context, now = Date.now()) {
    if (!session) return null;
    if (session.phase === 'complete') return { kind: 'complete', target: 'activity', title: 'Guided first trip complete', body: `${session.extracted} units extracted and ${session.sold} sold through ${session.receipts.length} confirmed commands. Your first manual trip is recorded. Pause guidance to keep playing on your own.` };
    if (session.failure) return blocked(session.failure);
    const { agent, ships, actions, runs, form, selected, commandPreview } = context;
    const unresolved = actions.find(action => action.review_required || action.state === 'uncertain');
    if (unresolved) return blocked(`Command #${unresolved.id} needs reconciliation or review. Use Activity history. Do not repeat it. Guidance waits for its outcome.`, 'activity');
    if (runs.some(run => run.active_agent_id)) return blocked('Stop automation and let its in-flight action settle before starting this manual lesson.', 'stop');
    if (session.pending) {
        const action = actions.find(action => action.id === session.pending.id);
        if (action?.state === 'preview') {
            if (Date.parse(action.expires_at) <= now) return { kind: 'expired', target: 'activity', title: 'This saved preview expired', body: `Command #${action.id} was never approved. Clear its expired preview tracking, then follow the same step to create a fresh preview.` };
            const intent = { command: session.pending.command, args: session.pending.args, next: session.pending.next };
            return commandPreview?.id === action.id
                ? { kind: 'approve', target: 'approve', title: `Review and approve ${action.command}`, body: `The saved preview #${action.id} still awaits approval. Inspect its ship, arguments, total, and reserve before approving.`, intent }
                : { kind: 'recover', target: 'activity', title: 'Restore your saved preview', body: `Preview #${action.id} is still awaiting approval. Restore it to inspect the exact same action. No new command will be created.`, intent };
        }
        return { kind: 'wait', target: 'activity', title: `Waiting for command #${session.pending.id}`, body: `${session.pending.command} is ${action?.state || 'not in the saved history'}. The queue worker must confirm this exact command before the tutorial continues. Refreshing this page does not execute queued work.` };
    }
    const other = actions.find(action => ['pending', 'sending'].includes(action.state));
    if (other) return blocked(`Existing command #${other.id} is ${other.state}. Let it settle first.`, 'activity');
    if (session.phase === 'welcome') return read('Your guided first trip', 'Each instruction names an exact value or action. Previews and approvals stay separate. This uses your real agent; guidance never sends a command for you. Click the highlighted Continue guided flight button to begin.', 'continue-guidance', 'sync');
    if (session.phase === 'sync') return syncStep('This fetches your actual fleet, credits, waypoints, and available prices. The route will be chosen from that fresh observation.');
    if (session.phase === 'plan') {
        const choice = chooseFlight(context, session.scouted, session.reserveFloor || 0);
        return choice.plan ? { kind: 'plan', target: 'guided-coach', title: 'Review your exact route', body: choice.plan.kind === 'mining'
            ? `Use ${choice.plan.ship}. Fly to ${choice.plan.site}, extract once, then sell at ${choice.plan.market} and fill the tank. Keep ${choice.plan.reserve} CR protected. Conservative route fuel: ${choice.plan.fuel}. The route stays fixed for this session.`
            : choice.plan.kind === 'setup' ? `No working miner is available. Use ${choice.plan.ship} at ${choice.plan.yard} to preview one ${choice.plan.type} for ${choice.plan.price} CR. Then synchronize and select the delivered miner. The approved mining route is ${choice.plan.site} → ${choice.plan.market}; protect ${choice.plan.reserve} CR.`
                : `First scout ${choice.plan.site} with ${choice.plan.ship} to observe missing market or shipyard details. The route needs ${choice.plan.fuel} estimated fuel units. ${choice.plan.fuelAllowance ? `Keep ${choice.plan.reserve} CR protected and ${choice.plan.fuelAllowance} CR available for fuel. Refueling needs a separate priced approval on arrival. ` : ''}Inspect actual fuel and buyer prices on arrival; listings alone do not prove prices. Approve each movement, then Synchronize there.`, plan: choice.plan }
            : { ...blocked(choice.reason, 'fleet'), canSync: true };
    }
    const plan = session.plan;
    const entry = ships.find(ship => ship.symbol === plan.ship);
    if (!entry) return blocked(`The planned ship ${plan.ship} is absent. Synchronize before resuming.`, 'sync');
    const ship = entry.snapshot;
    if (selected !== plan.ship) return { kind: 'select', target: `ship:${plan.ship}`, title: `Select ${plan.ship}`, body: 'Click the highlighted ship in Fleet. The tutorial uses this ship for the entire approved route.' };
    if (['inbound', 'marketSync', 'sell', 'refuel', 'review'].includes(session.phase) && ship.cargo.units !== session.extracted - session.sold) return blocked('Cargo differs from the confirmed extraction and sale totals. Inspect Activity history before continuing.', 'market');
    if (['marketSync', 'sell', 'refuel', 'review'].includes(session.phase) && (ship.nav.waypointSymbol !== plan.market || ship.nav.status !== 'DOCKED')) return blocked(`This step requires ${plan.ship} docked at ${plan.market}. Its position changed. Inspect Fleet before continuing.`, 'fleet');
    if (session.phase === 'briefing') return read(`Inspect ${plan.ship}`, `Check fuel ${ship.fuel.current}/${ship.fuel.capacity}, cargo ${ship.cargo.units}/${ship.cargo.capacity}, and ${ship.nav.status}. ${plan.kind === 'mining' ? 'This ship passed the mining equipment checks. ' : ''}Next you will use the highlighted command controls.`, 'fleet', 'outbound');
    if (session.phase === 'marketSync') return syncStep(`You are docked at ${plan.market}. Refresh its prices before setting exact sale or fuel totals.`);
    if (session.phase === 'review' || session.phase === 'complete') {
        if (session.extracted < 1 || session.sold !== session.extracted || ship.cargo.units !== 0 || ship.fuel.current !== ship.fuel.capacity || ship.nav.status !== 'DOCKED' || ship.nav.waypointSymbol !== plan.market) return blocked('The final ship or cargo state differs from the confirmed lesson outcomes. Inspect Activity history and synchronize before completing.');
        return { kind: session.phase === 'complete' ? 'complete' : 'read', target: 'activity', title: session.phase === 'complete' ? 'Guided first trip complete' : 'Review your confirmed results', body: `${session.extracted} units extracted and ${session.sold} sold. Cargo is empty, the tank is full, and ${plan.ship} is docked at ${plan.market}. ${session.receipts.length} approved commands were confirmed. Credits now: ${agent.credits} CR. Read Activity history to see each result.`, next: 'complete' };
    }
    if (ship.nav.status === 'IN_TRANSIT') {
        const arrival = Date.parse(ship.nav.route.arrival);
        return now >= arrival ? syncStep(`The reported arrival at ${ship.nav.waypointSymbol} has elapsed. Synchronize to confirm that ${plan.ship} is in orbit.`)
            : { kind: 'wait', target: 'fleet', title: 'Wait for arrival', body: `${plan.ship} is travelling to ${ship.nav.waypointSymbol}. Arrival: ${formatLocalTime(ship.nav.route.arrival)}. Guidance will highlight Synchronize when that time arrives.` };
    }
    let intent;
    const move = (target, next) => ship.nav.waypointSymbol !== target
        ? ship.nav.status === 'DOCKED' ? { command: 'orbit', args: {}, next } : { command: 'navigate', args: { waypointSymbol: target }, next }
        : null;
    if (session.phase === 'outbound') {
        const target = plan.kind === 'setup' ? plan.yard : plan.site;
        if (plan.kind === 'mining' && ship.nav.waypointSymbol === plan.refuelAt && ship.fuel.current < plan.fuel) {
            if (ship.nav.status !== 'DOCKED') intent = { command: 'dock', args: {}, next: 'outbound' };
            else {
                const quote = usableFuel(context.observations.find(observation => observation.kind === 'market' && observation.symbol === ship.nav.waypointSymbol)?.data);
                if (!quote) return { ...blocked('Refresh the fuel quote at the current market before preparing this route.', 'market'), canSync: true };
                const units = Math.min(ship.fuel.capacity - ship.fuel.current, quote.tradeVolume * 100);
                const cost = Math.ceil(units / 100) * quote.purchasePrice;
                if (agent.credits - cost < plan.reserve) return blocked(`The ${cost} CR preparation refuel would break the ${plan.reserve} CR protected reserve.`, 'market');
                intent = { command: 'refuel', args: { units, maxTotal: cost }, next: 'outbound' };
            }
        } else intent = move(target, 'outbound');
        if (!intent && plan.kind === 'scout') return syncStep(`You reached ${target}. Synchronize to reveal its prices and shipyard offers, then review the next exact route.`);
        if (!intent && plan.kind === 'setup') {
            const quote = context.observations.find(observation => observation.kind === 'market' && observation.symbol === plan.market);
            if (!quote?.data.tradeGoods?.length || !Number.isFinite(Date.parse(quote.observed_at)) || now - Date.parse(quote.observed_at) >= 180000) return syncStep(`Refresh the buyer quote at ${plan.market} before previewing this ship purchase. Setup requires market observations from the last three minutes.`);
            intent = { command: 'buy_ship', args: { shipType: plan.type, waypointSymbol: plan.yard, site: plan.site, market: plan.market, maxTotal: plan.price }, next: 'sync' };
        }
        if (!intent && plan.kind === 'mining') return read('Mining site reached', `${plan.ship} is at ${plan.site}. Next, enter orbit if needed and perform exactly one extraction.`, 'fleet', 'extract');
    }
    if (session.phase === 'extract') {
        if (ship.nav.waypointSymbol !== plan.site) return blocked(`The selected miner moved away from ${plan.site}. Pause guidance and inspect the route.`, 'fleet');
        if (ship.nav.status === 'DOCKED') intent = { command: 'orbit', args: {}, next: 'extract' };
        else if (Date.parse(ship.cooldown.expiration) > now) return { kind: 'wait', target: 'fleet', title: 'Wait for the mining cooldown', body: `Mining becomes available after ${formatLocalTime(ship.cooldown.expiration)}. Do not submit another extraction before then.` };
        else if (ship.cargo.units !== 0) return blocked('Mining starts with an empty hold. Cargo changed outside this guided trip. Inspect it before continuing.', 'market');
        else intent = { command: 'mine', args: {}, next: 'inbound' };
    }
    if (session.phase === 'inbound') {
        intent = move(plan.market, 'inbound');
        if (!intent) intent = ship.nav.status !== 'DOCKED' ? { command: 'dock', args: {}, next: 'marketSync' } : null;
        if (!intent) return syncStep(`At ${plan.market}, refresh the market before selling your extraction.`);
    }
    if (session.phase === 'sell') {
        const item = sorted(ship.cargo.inventory.filter(item => item.units > 0))[0];
        if (!item) return read('All extracted cargo sold', 'Your hold is empty. Next fill the fuel tank and inspect the recorded credit changes.', 'market', 'refuel');
        const quote = context.observations.find(observation => observation.kind === 'market' && observation.symbol === plan.market)?.data.tradeGoods?.find(good => good.symbol === item.symbol);
        if (!quote || quote.sellPrice < 1 || quote.tradeVolume < 1) return { ...blocked(`No observed buyer price or transaction volume for ${item.symbol} at ${plan.market}. Cargo stays aboard. Synchronize before retrying; do not repeat extraction.`, 'market'), canSync: true };
        const units = Math.min(item.units, quote.tradeVolume);
        intent = { command: 'sell', args: { symbol: item.symbol, units, minTotal: units * quote.sellPrice }, next: 'sell' };
    }
    if (session.phase === 'refuel') {
        const missing = ship.fuel.capacity - ship.fuel.current;
        if (!missing) return read('Fuel tank ready', 'The tank is full. No fuel purchase is needed. Review the confirmed trip results next.', 'fleet', 'review');
        const quote = context.observations.find(observation => observation.kind === 'market' && observation.symbol === plan.market)?.data.tradeGoods?.find(good => good.symbol === 'FUEL');
        if (!quote || quote.purchasePrice < 1 || quote.tradeVolume < 1) return { ...blocked(`No usable FUEL quote at ${plan.market}. Synchronize before continuing.`, 'market'), canSync: true };
        const units = Math.min(missing, quote.tradeVolume * 100);
        const cost = Math.ceil(units / 100) * quote.purchasePrice;
        if (agent.credits - cost < plan.reserve) return blocked(`The ${cost} CR refuel would break the ${plan.reserve} CR protected reserve. Keep the reserve and inspect the outcome.`, 'market');
        intent = { command: 'refuel', args: { units, maxTotal: cost }, next: 'refuel' };
    }
    if (!intent) return blocked('This lesson state needs a new synchronization.', 'sync');
    intent.args.reserve = plan.reserve;
    if (commandPreview) {
        const matches = commandPreview.ship_symbol === plan.ship && commandPreview.command === intent.command && Object.entries(intent.args).every(([key, value]) => commandPreview.arguments[key] === value);
        if (!matches || Date.parse(commandPreview.expires_at) <= now) return blocked('The open preview is different from this step or has expired. Click Cancel preview, then create the highlighted preview again.', 'cancel-preview');
        return { kind: 'approve', target: 'approve', title: `Review and approve ${intent.command}`, body: `Check ${plan.ship} and the displayed ${intent.command} arguments. ${intent.args.maxTotal !== undefined ? `Maximum total: ${intent.args.maxTotal} CR. ` : ''}${intent.args.minTotal !== undefined ? `Minimum sale total: ${intent.args.minTotal} CR. ` : ''}Protected reserve: ${plan.reserve} CR. Click the highlighted approval only if this matches.`, intent };
    }
    const fields = [{ name: 'reserve', target: 'reserve', value: plan.reserve, label: 'Protected credits' }];
    if (intent.command === 'navigate') fields.push({ name: 'destination', target: 'destination', value: intent.args.waypointSymbol, label: 'Destination' });
    if (intent.command === 'buy_ship') {
        const index = context.offers.findIndex(offer => offer.yard === plan.yard && offer.type === plan.type);
        if (index < 0) return blocked('The planned shipyard offer is no longer observed. Synchronize and restart the route review.', 'setup');
        fields.push({ name: 'site', target: 'site', value: plan.site, label: 'Mining site' }, { name: 'buyer', target: 'buyer', value: plan.market, label: 'Sale and refueling market' }, { name: 'offerIndex', target: 'offer', value: String(index), label: 'Observed shipyard offer' });
    }
    if (intent.args.symbol) fields.push({ name: 'good', target: 'good', value: intent.args.symbol, label: 'Trade good' });
    if (intent.args.units !== undefined) fields.push({ name: 'quantity', target: 'quantity', value: intent.args.units, label: 'Quantity / refuel tank units' });
    const bound = intent.args.maxTotal ?? intent.args.minTotal;
    if (bound !== undefined) fields.push({ name: 'priceBound', target: 'price', value: bound, label: 'Maximum cost / minimum sale total' });
    const field = fields.find(field => String(form[field.name]) !== String(field.value));
    if (field) return { kind: 'field', target: field.target, title: `Set ${field.label} to ${field.value}`, body: `Use the highlighted field. ${field.name === 'priceBound' ? `This is the total for this ${intent.command}, not a price per unit. ` : ''}${field.name === 'quantity' && intent.command === 'refuel' ? 'This quantity fills tank units, not cargo. ' : ''}The next control will be highlighted when this value matches.`, field, intent };
    return { kind: 'preview', target: `preview-${intent.command}`, title: `Click Preview ${intent.command === 'mine' ? 'mining' : intent.command === 'dock' ? 'docking' : intent.command === 'navigate' ? 'navigation' : intent.command === 'sell' ? 'cargo sale' : intent.command === 'refuel' ? 'refueling' : intent.command === 'buy_ship' ? 'one-time miner purchase' : intent.command}`, body: `Prepared for ${plan.ship}${intent.args.waypointSymbol ? ` → ${intent.args.waypointSymbol}` : ''}. This only creates a preview. Read it before the separate approval step.`, intent };
}

export function settleFlight(session, action) {
    if (session.failure) return session;
    if (!session.pending || action.id !== session.pending.id) return session;
    if (action.state === 'rejected' || action.state === 'acknowledged') return { ...session, pending: null, failure: `Command #${action.id} did not produce a confirmed lesson outcome. ${action.message || 'Inspect Activity history.'} Pause or restart guidance after resolving it.` };
    if (action.state !== 'confirmed' || action.review_required) return session;
    if (session.receipts.includes(action.id)) return session;
    if (action.command !== session.pending.command || action.ship_symbol !== session.plan.ship) return { ...session, pending: null, failure: 'The recorded command does not match the guided ship and action. Inspect Activity history.' };
    if (action.command === 'mine' && !(action.outcome?.yield?.units > 0)) return { ...session, pending: null, failure: 'This extraction returned no cargo. The tutorial stopped after one attempt. Inspect the result before planning another trip.' };
    if (action.command === 'buy_ship' && action.outcome?.configuration_mismatch) return { ...session, pending: null, failure: 'The delivered miner differs from its approved configuration. Inspect the delivered ship in Fleet. The purchase is recorded; do not buy another miner.' };
    return { ...session, phase: session.pending.next, pending: null, receipts: [...session.receipts, action.id], extracted: session.extracted + (action.command === 'mine' ? action.outcome.yield.units : 0), sold: session.sold + (action.command === 'sell' ? action.arguments.units : 0) };
}
