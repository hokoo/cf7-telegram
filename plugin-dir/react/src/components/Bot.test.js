import {apiUpdateBotToken} from '../utils/api';
import {getUpdateDiagnostic, saveBotTokenTransactionally} from './Bot';

jest.mock('./BotView', () => () => null);

jest.mock('../utils/api', () => ({
    apiDeleteBot: jest.fn(),
    apiFetchUpdates: jest.fn(),
    apiPingBot: jest.fn(),
    apiUpdateBotToken: jest.fn(),
    fetchBot: jest.fn(),
}));

jest.mock('../utils/main', () => ({
    connectChat2Channel: jest.fn(),
    disconnectConnectionBot2Chat: jest.fn(),
    setBot2ChatConnectionStatus: jest.fn(),
}));

describe('saveBotTokenTransactionally', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        apiUpdateBotToken.mockResolvedValue({identityChanged: false, relationsReset: false});
    });

    it('leaves client relations untouched when token validation fails', async () => {
        apiUpdateBotToken.mockRejectedValueOnce(new Error('Invalid token'));
        const pingBot = jest.fn();
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await expect(saveBotTokenTransactionally({
            botId: 1,
            token: '  new-token  ',
            pingBot,
            bot2ChatConnections: [
                {data: {id: 11, from: 1, to: 101}},
                {data: {id: 12, from: 2, to: 202}},
            ],
            setBot2ChatConnections,
            bot2ChannelConnections: [
                {data: {id: 21, from: 1, to: 301}},
                {data: {id: 22, from: 2, to: 302}},
            ],
            setBot2ChannelConnections,
        })).rejects.toThrow('Invalid token');

        expect(apiUpdateBotToken).toHaveBeenCalledWith(1, 'new-token');
        expect(pingBot).not.toHaveBeenCalled();
        expect(setBot2ChatConnections).not.toHaveBeenCalled();
        expect(setBot2ChannelConnections).not.toHaveBeenCalled();
    });

    it('preserves relations when the token still belongs to the same bot', async () => {
        const pingBot = jest.fn().mockResolvedValue(false);
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await saveBotTokenTransactionally({
            botId: 1,
            token: 'new-token',
            pingBot,
            bot2ChatConnections: [
                {data: {id: 11, from: 1, to: 101}},
                {data: {id: 12, from: 2, to: 202}},
            ],
            setBot2ChatConnections,
            bot2ChannelConnections: [
                {data: {id: 21, from: 1, to: 301}},
                {data: {id: 22, from: 2, to: 302}},
            ],
            setBot2ChannelConnections,
        });

        expect(setBot2ChatConnections).not.toHaveBeenCalled();
        expect(setBot2ChannelConnections).not.toHaveBeenCalled();
        expect(pingBot).toHaveBeenCalledWith({force: true, skipEditingCheck: true});
    });

    it('removes only current bot relations from client state after server reset', async () => {
        apiUpdateBotToken.mockResolvedValueOnce({identityChanged: true, relationsReset: true});
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await saveBotTokenTransactionally({
            botId: 1,
            token: 'new-token',
            pingBot: jest.fn().mockResolvedValue(true),
            bot2ChatConnections: [],
            setBot2ChatConnections,
            bot2ChannelConnections: [],
            setBot2ChannelConnections,
        });

        const chatUpdater = setBot2ChatConnections.mock.calls[0][0];
        const channelUpdater = setBot2ChannelConnections.mock.calls[0][0];
        expect(chatUpdater([{data: {from: 1}}, {data: {from: 2}}])).toEqual([{data: {from: 2}}]);
        expect(channelUpdater([{data: {from: 1}}, {data: {from: 2}}])).toEqual([{data: {from: 2}}]);
    });
});

describe('getUpdateDiagnostic', () => {
    it('distinguishes webhook conflicts and update errors', () => {
        expect(getUpdateDiagnostic({hasWebhookConflict: true, errors: []})).toBe('webhook_conflict');
        expect(getUpdateDiagnostic({hasWebhookConflict: false, errors: [{errorType: 'transport'}]})).toBe('update_error');
        expect(getUpdateDiagnostic({hasWebhookConflict: false, errors: []})).toBeNull();
    });
});
