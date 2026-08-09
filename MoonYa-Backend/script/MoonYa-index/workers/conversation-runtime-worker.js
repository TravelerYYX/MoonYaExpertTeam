'use strict';

const ports = new Set();
const conversations = new Map();
let globalSeq = 0;

function stateFor(id) {
    const key = String(id || '');
    if (!conversations.has(key)) {
        conversations.set(key, {
            conversationId: key,
            phase: 'idle',
            clientMessageId: null,
            runId: null,
            eventSeq: 0,
            composer: {},
            ownerClientId: null,
            lastTerminalStatus: null,
            unreadTerminal: false,
            reconnectAttempt: 0,
            reconnectMax: 5,
            lastNetworkError: null
        });
    }
    return conversations.get(key);
}

function broadcast(message) {
    ports.forEach(port => {
        try { port.postMessage(message); } catch (_) {}
    });
}

function reply(port, requestId, ok, data, error) {
    port.postMessage({ type: 'response', requestId, ok, data: data || null, error: error || null });
}

onconnect = function(event) {
    const port = event.ports[0];
    ports.add(port);
    port.onmessage = function(messageEvent) {
        const message = messageEvent.data || {};
        const action = String(message.action || '');
        const requestId = message.requestId || null;
        const conversationId = String(message.conversationId || '');
        const state = conversationId ? stateFor(conversationId) : null;

        if (action === 'activate') {
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'patchComposer' && state) {
            state.composer = Object.assign({}, state.composer, message.patch || {});
            state.eventSeq += 1;
            broadcast({ type: 'snapshot', conversationId, state });
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'start' && state) {
            if (['starting', 'running', 'waiting_approval', 'recovering', 'stopping'].includes(state.phase)) {
                reply(port, requestId, false, state, 'conversation_task_already_running');
                return;
            }
            state.phase = 'running';
            state.clientMessageId = message.clientMessageId || null;
            state.runId = message.runId || null;
            state.ownerClientId = message.clientId || null;
            state.lastTerminalStatus = null;
            state.unreadTerminal = false;
            state.reconnectAttempt = 0;
            state.lastNetworkError = null;
            state.eventSeq += 1;
            broadcast({ type: 'taskState', conversationId, state });
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'stop' && state) {
            if (['starting', 'running', 'waiting_approval', 'recovering'].includes(state.phase)
                && (!message.clientMessageId || state.clientMessageId === message.clientMessageId)) {
                state.phase = 'stopping';
                state.eventSeq += 1;
                broadcast({ type: 'taskState', conversationId, state });
                state.phase = 'idle';
                state.lastTerminalStatus = 'cancelled';
                state.runId = null;
                state.unreadTerminal = true;
                state.eventSeq += 1;
                broadcast({ type: 'taskState', conversationId, state });
            }
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'recover' && state) {
            if (['running', 'waiting_approval', 'recovering'].includes(state.phase)
                && (!message.clientMessageId || state.clientMessageId === message.clientMessageId)) {
                state.phase = 'recovering';
                state.reconnectAttempt = Math.max(1, Number(message.attempt || 1));
                state.reconnectMax = Math.max(state.reconnectAttempt, Number(message.maxAttempts || 5));
                state.lastNetworkError = message.error || null;
                state.eventSeq += 1;
                broadcast({ type: 'taskState', conversationId, state });
            }
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'reconnected' && state) {
            if (state.phase === 'recovering'
                && (!message.clientMessageId || state.clientMessageId === message.clientMessageId)) {
                state.phase = 'running';
                state.lastNetworkError = null;
                state.eventSeq += 1;
                broadcast({ type: 'taskState', conversationId, state });
            }
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'finish' && state) {
            if (['starting', 'running', 'waiting_approval', 'recovering', 'stopping'].includes(state.phase)
                && (!message.clientMessageId || state.clientMessageId === message.clientMessageId)) {
                state.phase = 'idle';
                state.lastTerminalStatus = message.status || 'completed';
                state.runId = null;
                state.unreadTerminal = true;
                state.lastNetworkError = null;
                state.eventSeq += 1;
                broadcast({ type: 'taskState', conversationId, state });
            }
            reply(port, requestId, true, state);
            return;
        }
        if (action === 'markViewed' && state) {
            state.unreadTerminal = false;
            state.eventSeq += 1;
            broadcast({ type: 'taskState', conversationId, state });
            reply(port, requestId, true, state);
            return;
        }
        if ((action === 'streamEvent' || action === 'officeEvent') && state) {
            globalSeq += 1;
            state.eventSeq = Math.max(state.eventSeq + 1, Number(message.eventSeq || 0), globalSeq);
            if (message.runId && state.runId !== message.runId) {
                state.runId = message.runId;
                broadcast({ type: 'taskState', conversationId, state });
            }
            broadcast({
                type: action,
                conversationId,
                originClientId: message.clientId || null,
                clientMessageId: message.clientMessageId || state.clientMessageId,
                runId: message.runId || state.runId,
                eventSeq: state.eventSeq,
                event: message.event || null
            });
            reply(port, requestId, true, { eventSeq: state.eventSeq });
            return;
        }
        if (action === 'disconnect') {
            ports.delete(port);
            try { port.close(); } catch (_) {}
            return;
        }
        reply(port, requestId, false, null, 'unknown_action');
    };
    port.start();
    port.postMessage({ type: 'ready' });
};
