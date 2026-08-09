'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

class FakePort {
    constructor() {
        this.messages = [];
        this.onmessage = null;
    }
    postMessage(message) { this.messages.push(JSON.parse(JSON.stringify(message))); }
    start() {}
    close() {}
    send(message) { this.onmessage({ data: message }); }
    response(requestId) {
        return this.messages.findLast(message => message.type === 'response' && message.requestId === requestId);
    }
}

const workerPath = path.join(__dirname, '..', 'script', 'MoonYa-index', 'workers', 'conversation-runtime-worker.js');
const sandbox = { Set, Map, Math, Number, String, Object, Array, JSON };
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(workerPath, 'utf8'), sandbox, { filename: workerPath });

const main = new FakePort();
const popout = new FakePort();
sandbox.onconnect({ ports: [main] });
sandbox.onconnect({ ports: [popout] });

main.send({ action: 'start', requestId: 'start-a', clientId: 'main', conversationId: 11, clientMessageId: 'message-a' });
assert(main.response('start-a').ok === true, 'first task should acquire conversation 11');

popout.send({ action: 'start', requestId: 'duplicate-a', clientId: 'popout', conversationId: 11, clientMessageId: 'message-b' });
assert(popout.response('duplicate-a').ok === false, 'second task in the same conversation must be rejected');

popout.send({ action: 'start', requestId: 'start-b', clientId: 'popout', conversationId: 12, clientMessageId: 'message-c' });
assert(popout.response('start-b').ok === true, 'different conversations must run concurrently');

main.send({
    action: 'officeEvent', requestId: 'office-a', clientId: 'main', conversationId: 11,
    clientMessageId: 'message-a', runId: 'run-a', eventSeq: 7, event: { event: 'agent.turn.started' }
});
const mirroredOffice = popout.messages.findLast(message => message.type === 'officeEvent' && message.runId === 'run-a');
assert(mirroredOffice && mirroredOffice.originClientId === 'main', 'office event origin/run identity must be preserved');

popout.send({ action: 'stop', requestId: 'wrong-stop', clientId: 'popout', conversationId: 11, clientMessageId: 'message-b' });
assert(popout.response('wrong-stop').data.phase === 'running', 'stale client id must not stop another task');

popout.send({ action: 'stop', requestId: 'right-stop', clientId: 'popout', conversationId: 11, clientMessageId: 'message-a' });
assert(popout.response('right-stop').data.lastTerminalStatus === 'cancelled', 'either window should stop the matching task');
assert(popout.response('right-stop').data.unreadTerminal === true, 'terminal state should become unread');

main.send({ action: 'finish', requestId: 'late-finish', clientId: 'main', conversationId: 11, clientMessageId: 'message-a', status: 'completed' });
assert(main.response('late-finish').data.lastTerminalStatus === 'cancelled', 'late finish must not overwrite cancellation');

main.send({ action: 'markViewed', requestId: 'view-a', clientId: 'main', conversationId: 11 });
assert(main.response('view-a').data.unreadTerminal === false, 'markViewed should clear the shared terminal indicator');

popout.send({
    action: 'patchComposer', requestId: 'composer-b', clientId: 'popout', conversationId: 12,
    patch: { draft: 'second window draft', composer: { reasoningEffort: 'xhigh', projectName: 'Demo' } }
});
main.send({ action: 'activate', requestId: 'activate-b', clientId: 'main', conversationId: 12 });
const activated = main.response('activate-b').data;
assert(activated.composer.draft === 'second window draft', 'draft should synchronize through the worker');
assert(activated.composer.composer.projectName === 'Demo', 'composer context should synchronize through the worker');

main.send({
    action: 'recover', requestId: 'recover-b', clientId: 'main', conversationId: 12,
    clientMessageId: 'message-c', attempt: 3, maxAttempts: 5, error: 'unexpected EOF'
});
const recovering = main.response('recover-b').data;
assert(recovering.phase === 'recovering', 'unexpected EOF must keep the shared task active in recovering');
assert(recovering.reconnectAttempt === 3 && recovering.reconnectMax === 5, 'reconnect progress must be shared');

main.send({
    action: 'reconnected', requestId: 'reconnected-b', clientId: 'main', conversationId: 12,
    clientMessageId: 'message-c'
});
assert(main.response('reconnected-b').data.phase === 'running', 'successful reconnect must resume running');

main.send({
    action: 'finish', requestId: 'failed-b', clientId: 'main', conversationId: 12,
    clientMessageId: 'message-c', status: 'failed'
});
assert(main.response('failed-b').data.lastTerminalStatus === 'failed', 'explicit reconnect failure must become failed');

console.log('conversation runtime worker: PASS');
