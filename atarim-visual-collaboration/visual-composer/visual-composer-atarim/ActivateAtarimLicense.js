// import * as React from 'react';
import React from 'react';
import styled from 'styled-components';

const InstallWrapper = styled.div`
    text-align: center;
    margin-top: 25px;
    color: #272D3C;
`;
const TextHeader = styled.div`
    font-size: 20px;
    font-family: Roboto, Helvetica, Arial, sans-serif;
    font-weight: 700;
    margin-bottom: 15px;
`;
const TextContent = styled.p`
    font-size: 15px;
    font-family: Roboto, Helvetica, Arial, sans-serif;
`;
const CommentButton = styled.button`
    color: #272D3C;
    height: 36px;
    font-size: 14px;
    max-height: 36px;
    font-family: Roboto, Helvetica, Arial, sans-serif;
    border-radius: 5px;
    background-color: #3ed696;
    box-sizing: border-box;
    padding: 6px 16px;
    width: 100%;
    font-weight: 500;
    border: none;
    cursor: pointer;
    margin: 15px 0;
`
const PowerByWraper = styled.div`
    display: flex;
    align-items: baseline;
    justify-content: center;
`;
const TextPower = styled.span`
    font-size: 15px;
    font-family: Roboto, Helvetica, Arial, sans-serif;
    font-weight: 600;
    padding: 0 15px;
`;

export default function AtarimLicence(props) {
  const {
    pluginData
  } = props

  return (
    <>
        <div 
            style={{
                display: "flex",
                justifyItems: "center",
                height: "100%",
                alignItems: "center"
            }}
        >
            <InstallWrapper>
                <TextHeader>
                    Welcome to Atarim
                </TextHeader>
                <div
                    style={{
                            overflow:"hidden", 
                            borderRadius:"15px", 
                            marginBottom: "15px",
                            border: "1px solid #dee2e6",
                    }}
                >
                    <img
                        src='https://wpfeedback-image.s3.us-east-2.amazonaws.com/media/permissions.svg'
                        alt='permission'
                    />
                </div>
                <PowerByWraper>
                    <TextPower>Please click on <a style={{color:"#272D3C"}} href={`${pluginData?.url}/wp-admin/admin.php?page=wpfeedback_page_permissions`}>
                        <strong>Permissions Tab</strong>
                        </a> and verify your 
                        licence to start using the plugin.
                    </TextPower>
                </PowerByWraper>
            </InstallWrapper>
        </div>
    </>
  );
}