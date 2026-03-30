import {
    disconnectConnectionBot2Channel,
    disconnectConnectionBot2Chat,
} from '../utils/main';
import {apiSaveBot} from '../utils/api';
import {saveBotTokenAndDisconnect} from './Bot';

jest.mock('./BotView', () => () => null);

jest.mock('../utils/api', () => ({
    apiDeleteBot: jest.fn(),
    apiFetchUpdates: jest.fn(),
    apiPingBot: jest.fn(),
    apiSaveBot: jest.fn(),
    fetchBot: jest.fn(),
}));

jest.mock('../utils/main', () => ({
    connectChat2Channel: jest.fn(),
    disconnectConnectionBot2Channel: jest.fn(),
    disconnectConnectionBot2Chat: jest.fn(),
    setBot2ChatConnectionStatus: jest.fn(),
}));

describe('saveBotTokenAndDisconnect', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        apiSaveBot.mockResolvedValue({});
        disconnectConnectionBot2Chat.mockResolvedValue(true);
        disconnectConnectionBot2Channel.mockResolvedValue(true);
    });

    it('does not disconnect chats when the updated bot stays offline', async () => {
        const pingBot = jest.fn().mockResolvedValue(false);
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await expect(saveBotTokenAndDisconnect({
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
        })).rejects.toThrow('Bot did not come online after token update.');

        expect(apiSaveBot).toHaveBeenCalledWith(1, '', 'new-token');
        expect(pingBot).toHaveBeenCalledWith({force: true, skipEditingCheck: true});
        expect(disconnectConnectionBot2Chat).not.toHaveBeenCalled();
        expect(disconnectConnectionBot2Channel).not.toHaveBeenCalled();
    });

    it('disconnects only the current bot relations after a successful token update', async () => {
        const pingBot = jest.fn().mockResolvedValue(true);
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await saveBotTokenAndDisconnect({
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

        expect(disconnectConnectionBot2Chat).toHaveBeenCalledTimes(1);
        expect(disconnectConnectionBot2Chat).toHaveBeenCalledWith(11, setBot2ChatConnections);
        expect(disconnectConnectionBot2Channel).toHaveBeenCalledTimes(1);
        expect(disconnectConnectionBot2Channel).toHaveBeenCalledWith(21, setBot2ChannelConnections);
    });
});
