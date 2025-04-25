/* global cf7TelegramData */

import {
    apiConnectBot2Channel,
    apiConnectChat2Channel,
    apiConnectForm2Channel,
    apiCreateChannel, apiDeleteChannel,
    apiDisconnectBot2Channel,
    apiDisconnectBot2Chat,
    apiDisconnectChat2Channel,
    apiDisconnectForm2Channel,
    apiSetBot2ChatConnectionStatus,
} from './api';

import chat2ChannelRelations from "../App";

export function copyWithTooltip(element, textToCopy = null) {
    const text = textToCopy || element.innerText;
    navigator.clipboard.writeText(text).then(() => {
        element.classList.add("copied");
        setTimeout(() => element.classList.remove("copied"), 1500);
    });
}

export const connectBot2Channel = async (botId, channelId, setBot2ChannelConnections) => {
    const result = await apiConnectBot2Channel(botId, channelId);
    if (result) {
        setBot2ChannelConnections(prev => [...prev, {data: result}]);
    }
}

export const disconnectConnectionBot2Channel = async (connectionId, setBot2ChannelConnections) => {
    const success = await apiDisconnectBot2Channel(connectionId);
    if (success) {
        setBot2ChannelConnections(prev => prev.filter(c => c.data.id !== connectionId));
    }
}

export const connectForm2Channel = async (formId, channelId, setForm2ChannelConnections) => {
    const result = await apiConnectForm2Channel(formId, channelId);
    if (result) {
        setForm2ChannelConnections(prev => [...prev, {data: result}]);
    }
}

export const disconnectConnectionForm2Channel = async (connectionId, setForm2ChannelConnections) => {
    const success = await apiDisconnectForm2Channel(connectionId);
    if (success) {
        setForm2ChannelConnections(prev => prev.filter(c => c.data.id !== connectionId));
    }
}

export const connectChat2Channel = async (chatId, channelId, setChat2ChannelConnections) => {
    const result = await apiConnectChat2Channel(chatId, channelId);
    if (result) {
        setChat2ChannelConnections(prev => [...prev, {data: result}]);
    }
};

export const disconnectChat2Channel = async (chatId, channelId, setChat2ChannelConnections) => {
    const connection = chat2ChannelRelations.find(c => c.data.from === chatId && c.data.to === channelId);

    if (connection) {
        await disconnectConnectionChat2Channel(connection.id, setChat2ChannelConnections)
    }
}

export const disconnectConnectionChat2Channel = async (connectionId, setChat2ChannelConnections) => {
    const success = await apiDisconnectChat2Channel(connectionId);
    if (success) {
        setChat2ChannelConnections(prev => prev.filter(c => c.data.id !== connectionId));
    }
};

export const setBot2ChatConnectionStatus = async (connectionId, status, setBot2ChatConnections) => {
    const result = await apiSetBot2ChatConnectionStatus(connectionId, status);
    if (result) {
        setBot2ChatConnections(prev => {
            const updatedConnections = [...prev];
            const index = updatedConnections.findIndex(c => c.data.id === connectionId);
            if (index !== -1) {
                updatedConnections[index].data.meta.status[0] = status;
            }
            return updatedConnections;
        });
    }

    return null;
}

export const disconnectConnectionBot2Chat = async (connectionId, setBot2ChatConnections) => {
    const success = await apiDisconnectBot2Chat(connectionId);
    if (success) {
        setBot2ChatConnections(prev => prev.filter(c => c.data.id !== connectionId));
    }
}

export const createChannel = async (name, setChannels) => {
    let createdChannel = await apiCreateChannel(name);
    if (createdChannel) {
        setChannels(prev => [...prev, createdChannel]);
    }
}

export const deleteChannel = async (channelId, setChannels) => {
    const success = await apiDeleteChannel(channelId);
    if (success) {
        setChannels(prev => prev.filter(channel => channel.id !== channelId));
    }
}

export function sprintf(template, ...args) {
    let i = 0;
    return template.replace(/%s/g, () => args[i++]);
}
