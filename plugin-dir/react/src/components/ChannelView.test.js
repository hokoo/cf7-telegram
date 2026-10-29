import React from 'react';
import {render, screen} from '@testing-library/react';
import ChannelView from './ChannelView';

const renderChannelView = (props = {}) => render(
    <ChannelView
        channel={{id: 20}}
        titleValue="Bridge Name"
        saving={false}
        error=""
        handleTitleClick={jest.fn()}
        handleTitleChange={jest.fn()}
        handleKeyDown={jest.fn()}
        handleCancelEdit={jest.fn()}
        saveTitle={jest.fn()}
        deleteChannel={jest.fn()}
        getToggleButtonLabel={jest.fn()}
        dataAvailability={{forms: 'ready', bots: 'ready', chats: 'ready'}}
        {...props}
    />
);

describe('ChannelView bridge copy', () => {
    it('uses bridge wording for empty states and removal', () => {
        renderChannelView();

        expect(screen.getByText('[No chats assigned to this bridge]')).toBeInTheDocument();
        expect(screen.getByText('[No forms assigned to this bridge]')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: 'Remove Bridge'})).toBeInTheDocument();
    });

    it('distinguishes same-name topics in the bridge list and keeps pending topics hidden', () => {
        renderChannelView({
            botForChannel: {
                id: 1,
                title: {rendered: 'Bot'},
                chats: [
                    {id: 10, title: {rendered: 'Same label'}, customName: 'Same label', telegramName: 'Operations', messageThreadID: 21},
                    {id: 11, title: {rendered: 'Same label'}, customName: '', telegramName: 'Support', telegramTopicName: 'Questions', messageThreadID: 22},
                    {id: 12, title: {rendered: 'Support'}, messageThreadID: 23},
                ],
            },
            bot2ChatConnections: [
                {data: {from: 1, to: 10, meta: {status: ['active']}}},
                {data: {from: 1, to: 11, meta: {status: ['active']}}},
                {data: {from: 1, to: 12, meta: {status: ['pending']}}},
            ],
        });

        expect(screen.getByTestId('cf7tg-channel-20-chat-10').querySelector('.chat-username')).toHaveTextContent('Operations');
        expect(screen.getByTestId('cf7tg-channel-20-chat-10').querySelector('.topic-identity')).toHaveTextContent('Same label');
        expect(screen.getByTestId('cf7tg-channel-20-chat-10').querySelector('.topic-identity')).toHaveAttribute('title', 'Topic #21');
        expect(screen.getByTestId('cf7tg-channel-20-chat-11').querySelector('.chat-username')).toHaveTextContent('Support');
        expect(screen.getByTestId('cf7tg-channel-20-chat-11').querySelector('.topic-identity')).toHaveTextContent('Questions');
        expect(screen.queryByTestId('cf7tg-channel-20-chat-12')).not.toBeInTheDocument();
    });
});
