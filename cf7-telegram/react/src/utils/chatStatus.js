// utils/chatStatus.js

export function getChatStatus(botId, chatId, relations = []) {
    const relation = relations.find(rel => rel.data.from === botId && rel.data.to === chatId);
    const status = relation?.data?.meta?.status?.[0];

    if (status === 'pending') return 'Pending';
    if (status === 'muted') return 'Muted';
    return 'Active';
}

export function getToggleButtonLabel(status) {
    return status === 'Muted' ? '▶️ Activate' : '⏹️ Mute';
}
