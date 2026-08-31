import {apiSetBot2ChatConnectionStatus} from './api';
import {setBot2ChatConnectionStatus} from './main';

jest.mock('./api', () => ({
    apiConnectBot2Channel: jest.fn(),
    apiConnectChat2Channel: jest.fn(),
    apiConnectForm2Channel: jest.fn(),
    apiCreateChannel: jest.fn(),
    apiDeleteChannel: jest.fn(),
    apiDisconnectBot2Channel: jest.fn(),
    apiDisconnectBot2Chat: jest.fn(),
    apiDisconnectChat2Channel: jest.fn(),
    apiDisconnectForm2Channel: jest.fn(),
    apiSetBot2ChatConnectionStatus: jest.fn(),
}));

describe('setBot2ChatConnectionStatus', () => {
    it('updates nested relation metadata without mutating previous state', async () => {
        apiSetBot2ChatConnectionStatus.mockResolvedValueOnce({id: 11});
        const original = [
            {data: {id: 11, meta: {status: ['pending'], source: ['fixture']}}},
            {data: {id: 12, meta: {status: ['active']}}},
        ];
        const snapshot = JSON.parse(JSON.stringify(original));
        const setConnections = jest.fn();

        const result = await setBot2ChatConnectionStatus(11, 'active', setConnections);
        const updated = setConnections.mock.calls[0][0](original);

        expect(result).toEqual({id: 11});
        expect(original).toEqual(snapshot);
        expect(updated).not.toBe(original);
        expect(updated[0]).not.toBe(original[0]);
        expect(updated[0].data.meta).toEqual({status: ['active'], source: ['fixture']});
        expect(updated[1]).toBe(original[1]);
    });
});
