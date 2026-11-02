export function shipSelectionKey(userId, agent) {
    return `spacetraders:ship:${userId}:${agent.id}:${agent.reset_id || 'unknown'}`;
}
