/* global cf7TelegramData, wp */

export function getChatStatus(botId, chatId, relations = []) {
    const relation = relations.find(rel => rel.data.from === botId && rel.data.to === chatId);
    const status = relation?.data?.meta?.status?.[0];

    if (status === 'pending') return 'Pending';
    if (status === 'muted') return 'Muted';
    return 'Active';
}

export function getToggleButtonLabel(status) {
    return ['muted', 'pending'].includes(status.toLowerCase()) ?
        wp.i18n.__( 'Activate', 'cf7-telegram') :
        wp.i18n.__( 'Mute', 'cf7-telegram');
}
