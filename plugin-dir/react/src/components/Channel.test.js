import React from 'react';
import {act, render} from '@testing-library/react';
import {connectBot2Channel, connectForm2Channel, deleteChannel, disconnectConnectionForm2Channel} from '../utils/main';
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

    it('uses bridge wording when confirming form removal', async () => {
        const confirm = jest.spyOn(window, 'confirm').mockReturnValue(false);

        await act(async () => {
            render(
                <Channel
                    channel={{id: 20, title: {rendered: 'Bridge'}}}
                    forms={[{id: 10, title: 'Contact Form'}]}
                    setChannels={jest.fn()}
                    form2ChannelConnections={[{data: {id: 30, from: 10, to: 20}}]}
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
            await mockChannelViewProps.handleRemoveForm(10);
        });

        expect(confirm).toHaveBeenCalledWith('Are you sure you want to remove this form from the bridge?');
        expect(disconnectConnectionForm2Channel).not.toHaveBeenCalled();

        confirm.mockRestore();
    });

    it('uses bridge wording when confirming deletion', async () => {
        const confirm = jest.spyOn(window, 'confirm').mockReturnValue(false);

        await act(async () => {
            render(
                <Channel
                    channel={{id: 20, title: {rendered: 'Bridge'}}}
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
            await mockChannelViewProps.deleteChannel();
        });

        expect(confirm).toHaveBeenCalledWith('Are you sure you want to delete this bridge?');
        expect(deleteChannel).not.toHaveBeenCalled();

        confirm.mockRestore();
    });
});
