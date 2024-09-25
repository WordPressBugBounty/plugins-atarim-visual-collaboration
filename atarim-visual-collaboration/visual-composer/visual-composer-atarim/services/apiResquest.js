let baseUrl = "https://api.atarim.io/"
export const getData = async (url) => {
    const response = await fetch(`${url}`, {
      method: 'get',
      headers: {
        'Content-Type': 'application/json',
    },
    })
    return await response.json()
}

export const postData = async (url,data) => {
  const response = await fetch(`${baseUrl}${url}`, {
    method: 'post',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      // 'Access-Control-Allow-Origin':'*',
      // 'Access-Control-Allow-Methods':'POST,PATCH,OPTIONS'
  },
  body: JSON.stringify(data)
  })
  return await response.json()
}

export const postUploadFile = async (url,data) => {
  const response = await fetch(`${baseUrl}${url}`, {
    method: 'post',
    headers: {
    
  },
  body: data
  })
  return await response.json()
}