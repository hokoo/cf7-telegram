import React from 'react';
import {act, render} from '@testing-library/react';
import {connectBot2Channel, connectForm2Channel} from '../utils/main';
import Channel from './Channel';

let mockChannelViewProps;

jest.mock('./ChannelView', () => (props) => {
    mockChannelViewProps = props;
    return null;
});

jest.mock('../utils/main', () => ({
    connectBot2Channel: jest.fn(),
    connectChat2Channel: jest.fn(),
    connectForm2Channel: jest.fn(),
    deleteChannel: jest.fn(),
    disconnectConnectionBot2Channel: jest.fn(),
    disconnectConnectionChat2Channel: jest.fn(),
    disconnectConnectionForm2Channel: jest.fn(),
}));

jest.mock('../utils/api', () => ({
    apiSaveChannel: jest.fn(),
}));

describe('Channel relation selections', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        mockChannelViewProps = null;
    });

    it('does not issue mutations when a clearable selector is cleared', async () => {
        await act(async () => {
            render(
                <Channel
                    channel={{id: 20, title: {rendered: 'Channel'}}}
                    forms={[]}
                    setChannels={jest.fn()}
                    form2ChannelConnections={[]}
                    setForm2ChannelConnections={jest.fn()}
                    bots={[]}
                    bot2ChannelConnections={[]}
                    setBot2ChannelConnections={jest.fn()}
                    chats={[]}
                    chat2ChannelConnections={[]}
                    setChat2ChannelConnections={jest.fn()}
                    bot2ChatConnections={[]}
                    dataAvailability={{forms: 'ready', bots: 'ready', chats: 'ready'}}
                />
            );
            await Promise.resolve();
        });

        await act(async () => {
            await mockChannelViewProps.handleBotSelect({target: {value: undefined}});
            await mockChannelViewProps.handleFormSelect({target: {value: undefined}});
        });

        expect(connectBot2Channel).not.toHaveBeenCalled();
        expect(connectForm2Channel).not.toHaveBeenCalled();
    });
});
