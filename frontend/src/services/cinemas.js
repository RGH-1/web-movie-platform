import api from './api';

export async function getNearbyCinemas({latitude, longitude, radius},{signal}={}){
    const response = await api.get('/v1/cinemas/nearby',{
        params:{lat:latitude, lng:longitude, radius},
        signal,
    });
    return response.data.data;
}